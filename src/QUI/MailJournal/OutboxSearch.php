<?php

namespace QUI\MailJournal;

use Doctrine\DBAL\Exception;
use QUI;
use QUI\Utils\Grid;

use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function preg_split;
use function strtolower;
use function strtoupper;
use function trim;

class OutboxSearch
{
    /**
     * @param array<string, mixed> $searchParams
     * @return array<string, mixed>
     * @throws Exception
     */
    public static function searchForGrid(array $searchParams = []): array
    {
        $Grid = new Grid();
        $query = $Grid->parseDBParams($searchParams);
        $tableOutbox = QUI::getDBTableName('mail_journal_outbox');
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $sortOn = 'send_date';
        $sortBy = 'DESC';
        $allowedSortOn = [
            'send_date',
            'create_date',
            'subject',
            'mail_from',
            'archived'
        ];

        if (!empty($searchParams['sortOn']) && in_array($searchParams['sortOn'], $allowedSortOn, true)) {
            $sortOn = $searchParams['sortOn'];
        }

        if (!empty($searchParams['sortBy']) && strtoupper((string)$searchParams['sortBy']) === 'ASC') {
            $sortBy = 'ASC';
        }

        $where = [];
        $binds = [];

        if (!empty($searchParams['search'])) {
            self::appendFreeTextSearch(
                trim((string)$searchParams['search']),
                $where,
                $binds,
                $tableAttachments
            );
        }

        if (isset($searchParams['archived']) && $searchParams['archived'] !== '') {
            $where[] = 'o.archived = :archived';
            $binds['archived'] = (int)$searchParams['archived'];
        }

        if (!empty($searchParams['dateFrom'])) {
            $where[] = 'o.send_date >= :dateFrom';
            $binds['dateFrom'] = (string)$searchParams['dateFrom'];
        }

        if (!empty($searchParams['dateTo'])) {
            $where[] = 'o.send_date <= :dateTo';
            $binds['dateTo'] = (string)$searchParams['dateTo'];
        }

        if (isset($searchParams['hasAttachments']) && $searchParams['hasAttachments'] !== '') {
            if (self::toBool($searchParams['hasAttachments'])) {
                $where[] = 'EXISTS (SELECT 1 FROM `' . $tableAttachments . '` ax WHERE ax.mail_id = o.id)';
            } else {
                $where[] = 'NOT EXISTS (SELECT 1 FROM `' . $tableAttachments . '` ax WHERE ax.mail_id = o.id)';
            }
        }

        $firstResult = null;
        $maxResults = null;

        if (!empty($query['limit'])) {
            $limit = explode(',', $query['limit']);
            $start = (int)$limit[0];
            $max = (int)($limit[1] ?? 20);
            $firstResult = $start;
            $maxResults = $max;
        }

        $Connection = QUI::getDataBaseConnection();
        $QueryBuilder = $Connection->createQueryBuilder();

        $QueryBuilder
            ->select(
                'o.id',
                'o.create_date',
                'o.send_date',
                'o.subject',
                'o.mail_from',
                'o.mail_from_name',
                'o.mail_to',
                'o.archived',
                'COUNT(a.id) AS attachment_count'
            )
            ->from($tableOutbox, 'o')
            ->leftJoin('o', $tableAttachments, 'a', 'a.mail_id = o.id')
            ->groupBy('o.id')
            ->orderBy('o.' . $sortOn, $sortBy);

        foreach ($where as $wherePart) {
            $QueryBuilder->andWhere($wherePart);
        }

        foreach ($binds as $name => $value) {
            $QueryBuilder->setParameter($name, $value);
        }

        if ($firstResult !== null) {
            $QueryBuilder->setFirstResult($firstResult);
        }

        if ($maxResults !== null) {
            $QueryBuilder->setMaxResults($maxResults);
        }

        $rows = $QueryBuilder->fetchAllAssociative();

        $CountQueryBuilder = $Connection->createQueryBuilder();

        $CountQueryBuilder
            ->select('COUNT(*)')
            ->from($tableOutbox, 'o');

        foreach ($where as $wherePart) {
            $CountQueryBuilder->andWhere($wherePart);
        }

        foreach ($binds as $name => $value) {
            $CountQueryBuilder->setParameter($name, $value);
        }

        $total = (int)$CountQueryBuilder->fetchOne();

        $data = [];

        foreach ($rows as $row) {
            $row['archived'] = (int)$row['archived'];
            $row['attachment_count'] = (int)$row['attachment_count'];
            $row['mail_to_display'] = self::formatAddressList($row['mail_to']);
            $data[] = $row;
        }

        return $Grid->parseResult($data, $total);
    }

    /**
     * @param array<int, string> $where
     * @param array<string, mixed> $binds
     */
    protected static function appendFreeTextSearch(
        string $search,
        array &$where,
        array &$binds,
        string $tableAttachments
    ): void {
        if ($search === '') {
            return;
        }

        $terms = preg_split('/\s+/u', $search) ?: [];
        $terms = array_values(array_filter($terms, static fn(string $term) => $term !== ''));

        if (!$terms) {
            return;
        }

        $fields = [
            'o.subject',
            'o.mail_from',
            'o.mail_from_name',
            'o.mail_to',
            'o.reply_to',
            'o.mail_cc',
            'o.mail_bcc',
            'o.body_text',
            'o.source_event'
        ];

        foreach ($terms as $i => $term) {
            $bindName = 'search_' . $i;
            $likes = [];

            foreach ($fields as $field) {
                $likes[] = $field . ' LIKE :' . $bindName;
            }

            $likes[] = 'EXISTS (SELECT 1 FROM `' . $tableAttachments . '` sa WHERE sa.mail_id = o.id AND sa.filename LIKE :' . $bindName . ')';

            $where[] = '(' . implode(' OR ', $likes) . ')';
            $binds[$bindName] = '%' . $term . '%';
        }
    }

    protected static function toBool(mixed $value): bool
    {
        $value = strtolower(trim((string)$value));

        if ($value === '') {
            return false;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    protected static function formatAddressList(?string $json): string
    {
        if (empty($json)) {
            return '';
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return $json;
        }

        $addresses = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $address = trim((string)($entry[0] ?? ''));
                $name = trim((string)($entry[1] ?? ''));

                if (!empty($name) && !empty($address)) {
                    $addresses[] = $name . ' <' . $address . '>';
                    continue;
                }

                if (!empty($address)) {
                    $addresses[] = $address;
                }

                continue;
            }

            if (is_string($entry)) {
                $entry = trim($entry);

                if (!empty($entry)) {
                    $addresses[] = $entry;
                }
            }
        }

        return implode(', ', $addresses);
    }
}
