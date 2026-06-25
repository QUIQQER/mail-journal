<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_downloadAttachment
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_downloadAttachment',
    static function ($attachmentId, $disposition) {
        if (!is_string($attachmentId) || trim($attachmentId) === '') {
            return;
        }

        $Connection = QUI::getDataBaseConnection();
        $tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

        $attachment = $Connection->createQueryBuilder()
            ->select('filename', 'mime_type', 'path')
            ->from($tableAttachments)
            ->where('id = :attachmentId')
            ->setParameter('attachmentId', trim($attachmentId))
            ->setMaxResults(1)
            ->fetchAssociative();

        if (!$attachment || empty($attachment['path']) || !is_string($attachment['path'])) {
            return;
        }

        $path = $attachment['path'];

        if (!is_file($path)) {
            return;
        }

        $filename = 'attachment';

        if (!empty($attachment['filename']) && is_string($attachment['filename'])) {
            $filename = basename($attachment['filename']);
        }

        $mimeType = 'application/octet-stream';

        if (!empty($attachment['mime_type']) && is_string($attachment['mime_type'])) {
            $mimeType = $attachment['mime_type'];
        }

        $filename = preg_replace('/[\r\n]+/', '', $filename) ?: 'attachment';
        $mimeType = preg_replace('/[\r\n]+/', '', $mimeType) ?: 'application/octet-stream';
        $disposition = $disposition === 'inline' ? 'inline' : 'attachment';

        header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Content-type: ' . $mimeType);
        header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($filename, '"\\') . '"');

        readfile($path);
        exit;
    },
    ['attachmentId', 'disposition'],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.view']
);
