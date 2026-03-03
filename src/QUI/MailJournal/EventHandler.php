<?php

namespace QUI\MailJournal;

use PHPMailer\PHPMailer\PHPMailer;
use QUI;
use QUI\Database\Exception;
use QUI\Mail\Mailer;
use QUI\Utils\Uuid;
use QUI\Utils\System\File;

use Throwable;

use function basename;
use function date;
use function file_exists;
use function filesize;
use function is_array;
use function json_encode;

class EventHandler
{
    protected const TABLE_OUTBOX = 'mail_journal_outbox';
    protected const TABLE_ATTACHMENTS = 'mail_journal_outbox_attachments';
    protected const LEGACY_MAILER_ATTACHMENTS_KEY = 'attachements';

    /**
     * Save each sent mail to the journal outbox.
     */
    public static function onMailerSend(Mailer $Mailer, PHPMailer $PHPMailer): void
    {
        try {
            $mailId = self::insertMail($Mailer, $PHPMailer);

            if (empty($mailId)) {
                return;
            }

            self::storeAttachments($mailId, $Mailer);
        } catch (Throwable $Throwable) {
            QUI\System\Log::writeException($Throwable, QUI\System\Log::LEVEL_ERROR, [], 'MailJournal');
        }
    }

    /**
     * @throws Exception
     */
    protected static function insertMail(Mailer $Mailer, PHPMailer $PHPMailer): string
    {
        $mailId = Uuid::get();

        QUI::getDataBase()->insert(
            QUI::getDBTableName(self::TABLE_OUTBOX),
            [
                'id' => $mailId,
                'create_date' => date('Y-m-d H:i:s'),
                'send_date' => date('Y-m-d H:i:s'),
                'subject' => $PHPMailer->Subject,
                'body_html' => $PHPMailer->Body,
                'body_text' => $PHPMailer->AltBody,
                'mail_from' => $PHPMailer->From,
                'mail_from_name' => $PHPMailer->FromName,
                'mail_to' => self::encodeAddressList($PHPMailer->getToAddresses()),
                'reply_to' => self::encodeAddressList($PHPMailer->getReplyToAddresses()),
                'mail_cc' => self::encodeAddressList($PHPMailer->getCcAddresses()),
                'mail_bcc' => self::encodeAddressList($PHPMailer->getBccAddresses()),
                'is_html' => (int)$Mailer->getAttribute('html'),
                'source_event' => null,
                'meta' => self::encodeMeta($Mailer)
            ]
        );

        return $mailId;
    }

    /**
     * @throws QUI\Exception
     * @throws Exception
     */
    protected static function storeAttachments(string $mailId, Mailer $Mailer): void
    {
        $mail = $Mailer->toArray();
        $attachments = self::extractAttachments($mail);

        if (empty($attachments)) {
            return;
        }

        $attachmentDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'attachments/' . $mailId . '/';
        File::mkdir($attachmentDir);

        foreach ($attachments as $attachmentPath) {
            $newPath = null;
            $filename = basename($attachmentPath);
            $fileSize = null;
            $mimeType = null;

            if (file_exists($attachmentPath)) {
                $newPath = $attachmentDir . $filename;

                try {
                    File::copy($attachmentPath, $newPath);
                } catch (Throwable) {
                    $newPath = null;
                }

                $fileInfo = File::getInfo($attachmentPath);

                if (isset($fileInfo['mime_type'])) {
                    $mimeType = $fileInfo['mime_type'];
                }

                $fileSize = filesize($attachmentPath) ?: null;
            }

            QUI::getDataBase()->insert(
                QUI::getDBTableName(self::TABLE_ATTACHMENTS),
                [
                    'id' => Uuid::get(),
                    'mail_id' => $mailId,
                    'create_date' => date('Y-m-d H:i:s'),
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'filesize' => $fileSize,
                    'path' => $newPath
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $mail
     * @return array<int, string>
     */
    protected static function extractAttachments(array $mail): array
    {
        if (!empty($mail['attachments']) && is_array($mail['attachments'])) {
            return $mail['attachments'];
        }

        if (
            !empty($mail[self::LEGACY_MAILER_ATTACHMENTS_KEY]) &&
            is_array($mail[self::LEGACY_MAILER_ATTACHMENTS_KEY])
        ) {
            // Core compatibility: Mailer::toArray currently returns "attachements".
            return $mail[self::LEGACY_MAILER_ATTACHMENTS_KEY];
        }

        return [];
    }

    /**
     * @param array<int, array<int, string>> $addresses
     */
    protected static function encodeAddressList(array $addresses): string
    {
        return json_encode($addresses) ?: '[]';
    }

    protected static function encodeMeta(Mailer $Mailer): string
    {
        return json_encode([
            'mailer' => $Mailer->toArray()
        ]) ?: '{}';
    }
}
