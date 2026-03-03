<?php

namespace QUI\MailJournal;

use QUI;
use QUI\Utils\Grid;

use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function json_decode;
use function strlen;
use function strtoupper;
use function trim;

class OutboxSearch
{
    /**
     * @param array<string, mixed> $searchParams
     * @return array<string, mixed>
     */
    public static function searchForGrid(array $searchParams = []): array
    {
        $Grid = new Grid();
        $query = $Grid->parseDBParams($searchParams);

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
            $where[] = '(o.subject LIKE :search OR o.mail_from LIKE :search OR o.mail_to LIKE :search)';
            $binds['search'] = '%' . trim((string)$searchParams['search']) . '%';
        }

        if (isset($searchParams['archived']) && $searchParams['archived'] !== '') {
            $where[] = 'o.archived = :archived';
            $binds['archived'] = (int)$searchParams['archived'];
        }

        $whereSql = '';

        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $limitSql = '';

        if (!empty($query['limit'])) {
            $limit = explode(',', $query['limit']);
            $start = (int)$limit[0];
            $max = (int)($limit[1] ?? 20);
            $limitSql = ' LIMIT ' . $start . ', ' . $max;
        }

        $tableOutbox = QUI::getDBTableName('mail_journal_outbox');
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $sql =
            'SELECT o.id, o.create_date, o.send_date, o.subject, o.mail_from, o.mail_from_name, o.mail_to, o.archived, ' .
            'COUNT(a.id) as attachment_count ' .
            'FROM `' . $tableOutbox . '` o ' .
            'LEFT JOIN `' . $tableAttachments . '` a ON a.mail_id = o.id ' .
            $whereSql .
            ' GROUP BY o.id ' .
            ' ORDER BY o.' . $sortOn . ' ' . $sortBy .
            $limitSql;

        $Stmt = QUI::getPDO()->prepare($sql);

        foreach ($binds as $name => $value) {
            $Stmt->bindValue(':' . $name, $value);
        }

        $Stmt->execute();
        $rows = $Stmt->fetchAll(\PDO::FETCH_ASSOC);

        $sqlCount =
            'SELECT COUNT(*) ' .
            'FROM `' . $tableOutbox . '` o ' .
            $whereSql;

        $StmtCount = QUI::getPDO()->prepare($sqlCount);

        foreach ($binds as $name => $value) {
            $StmtCount->bindValue(':' . $name, $value);
        }

        $StmtCount->execute();
        $total = (int)$StmtCount->fetchColumn();

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
     * @return array<string, mixed>
     */
    public static function getMailById(string $mailId): array
    {
        if (empty($mailId)) {
            return [];
        }

        $tableOutbox = QUI::getDBTableName('mail_journal_outbox');
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $Stmt = QUI::getPDO()->prepare(
            'SELECT id, create_date, send_date, subject, body_html, body_text, mail_from, mail_from_name, mail_to, reply_to, mail_cc, mail_bcc, is_html, source_event, meta, archived ' .
            'FROM `' . $tableOutbox . '` ' .
            'WHERE id = :mailId LIMIT 1'
        );

        $Stmt->bindValue(':mailId', $mailId);
        $Stmt->execute();

        $mail = $Stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$mail) {
            return [];
        }

        $StmtAttachments = QUI::getPDO()->prepare(
            'SELECT id, filename, mime_type, filesize, path ' .
            'FROM `' . $tableAttachments . '` ' .
            'WHERE mail_id = :mailId ' .
            'ORDER BY create_date ASC'
        );

        $StmtAttachments->bindValue(':mailId', $mailId);
        $StmtAttachments->execute();

        $attachments = $StmtAttachments->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($attachments as $i => $attachment) {
            $attachments[$i]['filesize'] = (int)($attachment['filesize'] ?? 0);
        }

        $meta = [];

        if (!empty($mail['meta'])) {
            $metaDecoded = json_decode((string)$mail['meta'], true);

            if (is_array($metaDecoded)) {
                $meta = $metaDecoded;
            }
        }

        $mail['is_html'] = (int)$mail['is_html'];
        $mail['archived'] = (int)$mail['archived'];
        $mail['mail_from_display'] = trim((string)$mail['mail_from_name'] . ' <' . (string)$mail['mail_from'] . '>', ' <>');
        $mail['mail_to_display'] = self::formatAddressList($mail['mail_to']);
        $mail['reply_to_display'] = self::formatAddressList($mail['reply_to']);
        $mail['mail_cc_display'] = self::formatAddressList($mail['mail_cc']);
        $mail['mail_bcc_display'] = self::formatAddressList($mail['mail_bcc']);
        $mail['attachments'] = $attachments;
        $mail['attachment_count'] = count($attachments);
        $mail['meta'] = $meta;

        if (is_array($mail['meta']) && !empty($mail['meta'])) {
            $jsonMeta = json_encode($mail['meta'], JSON_PRETTY_PRINT);

            if (is_string($jsonMeta) && strlen($jsonMeta)) {
                $mail['meta_json'] = $jsonMeta;
            } else {
                $mail['meta_json'] = '';
            }
        } else {
            $mail['meta_json'] = '';
        }

        return $mail;
    }

    protected static function formatAddressList(?string $json): string
    {
        if (empty($json)) {
            return '';
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return (string)$json;
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
