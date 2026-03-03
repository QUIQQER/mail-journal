<?php

namespace QUI\MailJournal;

use QUI;

class MailRepository
{
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

        $row = $Stmt->fetch(\PDO::FETCH_ASSOC);

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

        $attachments = $StmtAttachments->fetchAll(\PDO::FETCH_ASSOC);

        return Mail::fromDatabaseRow($row, $attachments);
    }
}
