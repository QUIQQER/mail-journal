<?php

namespace QUI\MailJournal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use QUI;
use QUI\Permissions\Permission;
use QUI\Utils\Doctrine as DoctrineUtils;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_string;
use function trim;

class MailRepository
{
    /**
     * @throws Exception
     */
    public function getById(string $mailId): ?Mail
    {
        if (empty($mailId)) {
            return null;
        }

        $Connection = QUI::getDataBaseConnection();
        $tableOutbox = DoctrineUtils::quoteIdentifier(QUI::getDBTableName('mail_journal_outbox'));
        $tableAttachments = DoctrineUtils::quoteIdentifier(
            QUI::getDBTableName('mail_journal_outbox_attachments')
        );

        $row = $Connection->createQueryBuilder()
            ->select(
                'id',
                'create_date',
                'send_date',
                'subject',
                'body_html',
                'body_text',
                'mail_from',
                'mail_from_name',
                'mail_to',
                'reply_to',
                'mail_cc',
                'mail_bcc',
                'is_html',
                'source_event',
                'meta',
                'archived'
            )
            ->from($tableOutbox)
            ->where('id = :mailId')
            ->setParameter('mailId', $mailId)
            ->setMaxResults(1)
            ->fetchAssociative();

        if (!$row) {
            return null;
        }

        $attachments = $Connection->createQueryBuilder()
            ->select('id', 'filename', 'mime_type', 'filesize', 'path')
            ->from($tableAttachments)
            ->where('mail_id = :mailId')
            ->setParameter('mailId', $mailId)
            ->orderBy('create_date', 'ASC')
            ->fetchAllAssociative();

        return Mail::fromDatabaseRow($row, $attachments);
    }

    /**
     * @throws Exception|QUI\Exception
     */
    public function deleteById(string $mailId): bool
    {
        return $this->deleteByIds([$mailId]) > 0;
    }

    /**
     * @param array<int, mixed> $mailIds
     * @throws Exception
     * @throws QUI\Exception
     */
    public function deleteByIds(array $mailIds): int
    {
        $this->assertDeletePermission();

        $mailIds = $this->normalizeIds($mailIds);

        if (!count($mailIds)) {
            return 0;
        }

        $Connection = QUI::getDataBaseConnection();
        $tableOutbox = DoctrineUtils::quoteIdentifier(QUI::getDBTableName('mail_journal_outbox'));
        $tableAttachments = DoctrineUtils::quoteIdentifier(
            QUI::getDBTableName('mail_journal_outbox_attachments')
        );

        $Connection->createQueryBuilder()
            ->delete($tableAttachments)
            ->where('mail_id IN (:mailIds)')
            ->setParameter('mailIds', $mailIds, ArrayParameterType::STRING)
            ->executeStatement();

        return (int)$Connection->createQueryBuilder()
            ->delete($tableOutbox)
            ->where('id IN (:mailIds)')
            ->setParameter('mailIds', $mailIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * @param array<int, mixed> $mailIds
     * @return array<int, string>
     */
    protected function normalizeIds(array $mailIds): array
    {
        $mailIds = array_map(
            static function ($mailId) {
                if (!is_string($mailId)) {
                    return '';
                }

                return trim($mailId);
            },
            $mailIds
        );

        $mailIds = array_filter(
            $mailIds,
            static fn(string $mailId) => $mailId !== ''
        );

        return array_values(array_unique($mailIds));
    }

    /**
     * @throws QUI\Exception
     */
    protected function assertDeletePermission(): void
    {
        Permission::checkPermission('quiqqer.mail-journal.delete');
    }
}
