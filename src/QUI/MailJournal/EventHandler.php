<?php

namespace QUI\MailJournal;

use Doctrine\DBAL\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use QUI;
use QUI\Mail\Mailer;
use QUI\Mail\Queue as MailerQueue;
use QUI\Utils\Uuid;
use QUI\Utils\System\File;
use Throwable;

use function basename;
use function date;
use function file_exists;
use function filesize;
use function is_array;
use function json_encode;
use function str_contains;

class EventHandler
{
    protected const TABLE_OUTBOX = 'mail_journal_outbox';
    protected const TABLE_ATTACHMENTS = 'mail_journal_outbox_attachments';
    protected const LEGACY_MAILER_ATTACHMENTS_KEY = 'attachements';

    /**
     * Save each sent mail to the journal outbox.
     */
    public static function onMailerSend(
        Mailer | MailerQueue $Mailer,
        PHPMailer $PHPMailer
    ): void {
        try {
            $mailId = self::insertMail($Mailer, $PHPMailer);

            if (empty($mailId)) {
                return;
            }

            self::storeAttachments($mailId, $PHPMailer);
        } catch (Throwable $Throwable) {
            QUI\System\Log::writeException($Throwable, QUI\System\Log::LEVEL_ERROR, [], 'MailJournal');
        }
    }

    /**
     * @throws Exception
     */
    protected static function insertMail(Mailer | MailerQueue $Mailer, PHPMailer $PHPMailer): string
    {
        $mailId = Uuid::get();
        $Connection = QUI::getDataBaseConnection();

        $Connection->insert(
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
                'is_html' => self::resolveIsHtml($Mailer, $PHPMailer),
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
    protected static function storeAttachments(string $mailId, PHPMailer $PHPMailer): void
    {
        $attachments = $PHPMailer->getAttachments();

        if (empty($attachments)) {
            return;
        }

        $attachmentDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'attachments/' . $mailId . '/';
        File::mkdir($attachmentDir);

        foreach ($attachments as $attachment) {
            if (!isset($attachment[0]) || !is_string($attachment[0])) {
                continue;
            }

            $attachmentPath = $attachment[0];
            $newPath = null;
            $filename = basename($attachmentPath);
            $fileSize = null;
            $mimeType = null;

            if (isset($attachment[2]) && is_string($attachment[2]) && !empty($attachment[2])) {
                $filename = $attachment[2];
            }

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

            QUI::getDataBaseConnection()->insert(
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

    protected static function encodeMeta(Mailer | MailerQueue $Mailer): string
    {
        if ($Mailer instanceof MailerQueue) {
            return json_encode([
                'mailer' => [
                    'type' => 'queue',
                    'class' => MailerQueue::class
                ]
            ]) ?: '{}';
        }

        return json_encode([
            'mailer' => $Mailer->toArray()
        ]) ?: '{}';
    }

    protected static function resolveIsHtml(Mailer | MailerQueue $Mailer, PHPMailer $PHPMailer): int
    {
        if ($Mailer instanceof Mailer) {
            return (int)$Mailer->getAttribute('html');
        }

        return (int)str_contains((string)$PHPMailer->ContentType, 'text/html');
    }
}
