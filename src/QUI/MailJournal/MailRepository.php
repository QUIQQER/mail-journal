<?php

namespace QUI\MailJournal;

use PDO;
use QUI;
use QUI\Exception;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function implode;
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

        $tableOutbox = QUI::getDBTableName('mail_journal_outbox');
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $Stmt = QUI::getPDO()->prepare(
            'SELECT id, create_date, send_date, subject, body_html, body_text, mail_from, mail_from_name, mail_to, reply_to, mail_cc, mail_bcc, is_html, source_event, meta, archived ' .
            'FROM `' . $tableOutbox . '` ' .
            'WHERE id = :mailId LIMIT 1'
        );

        $Stmt->bindValue(':mailId', $mailId);
        $Stmt->execute();

        $row = $Stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $StmtAttachments = QUI::getPDO()->prepare(
            'SELECT id, filename, mime_type, filesize, path ' .
            'FROM `' . $tableAttachments . '` ' .
            'WHERE mail_id = :mailId ' .
            'ORDER BY create_date ASC'
        );

        $StmtAttachments->bindValue(':mailId', $mailId);
        $StmtAttachments->execute();

        $attachments = $StmtAttachments->fetchAll(PDO::FETCH_ASSOC);

        return Mail::fromDatabaseRow($row, $attachments);
    }

    /**
     * @throws Exception
     */
    public function deleteById(string $mailId): bool
    {
        return $this->deleteByIds([$mailId]) > 0;
    }

    /**
     * @param array<int, mixed> $mailIds
     * @throws Exception
     */
    public function deleteByIds(array $mailIds): int
    {
        $mailIds = $this->normalizeIds($mailIds);

        if (!count($mailIds)) {
            return 0;
        }

        $placeholders = [];
        $binds = [];

        foreach ($mailIds as $i => $mailId) {
            $placeholder = ':id' . $i;
            $placeholders[] = $placeholder;
            $binds[$placeholder] = $mailId;
        }

        $inSql = implode(', ', $placeholders);
        $tableOutbox = QUI::getDBTableName('mail_journal_outbox');
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $StmtAttachments = QUI::getPDO()->prepare(
            'DELETE FROM `' . $tableAttachments . '` WHERE mail_id IN (' . $inSql . ')'
        );

        foreach ($binds as $name => $value) {
            $StmtAttachments->bindValue($name, $value);
        }

        $StmtAttachments->execute();

        $StmtOutbox = QUI::getPDO()->prepare(
            'DELETE FROM `' . $tableOutbox . '` WHERE id IN (' . $inSql . ')'
        );

        foreach ($binds as $name => $value) {
            $StmtOutbox->bindValue($name, $value);
        }

        $StmtOutbox->execute();

        return $StmtOutbox->rowCount();
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
}
