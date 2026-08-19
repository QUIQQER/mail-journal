<?php

namespace QUI\MailJournal;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use PDO;
use QUI;
use QUI\Cron\Manager;
use QUI\Utils\Doctrine as DoctrineUtils;
use QUI\Utils\System\File;
use RuntimeException;
use Throwable;

use function array_map;
use function count;
use function date;
use function extension_loaded;
use function file_exists;
use function is_string;
use function preg_match;
use function substr;
use function trim;

class Cron
{
    protected const TABLE_OUTBOX = 'mail_journal_outbox';
    protected const TABLE_ATTACHMENTS = 'mail_journal_outbox_attachments';

    /**
     * Archive all outbox mails older than 2 years into monthly SQLite files.
     *
     * @param array<string, mixed> $params
     */
    public static function archiveOldMails(array $params, Manager $CronManager): void
    {
        $Connection = QUI::getDataBaseConnection();
        $tableOutbox = QUI::getDBTableName(self::TABLE_OUTBOX);
        $tableAttachments = QUI::getDBTableName(self::TABLE_ATTACHMENTS);
        $cutoffDate = self::getCutoffDate();

        try {
            while (true) {
                $archiveDate = $Connection->createQueryBuilder()
                    ->select('MIN(COALESCE(send_date, create_date))')
                    ->from(DoctrineUtils::quoteIdentifier($tableOutbox))
                    ->where('COALESCE(send_date, create_date) < :cutoffDate')
                    ->setParameter('cutoffDate', $cutoffDate)
                    ->fetchOne();

                $month = self::extractArchiveMonth($archiveDate);

                if ($month === null) {
                    return;
                }

                self::archiveMonth($month, $tableOutbox, $tableAttachments);
            }
        } catch (Throwable $Throwable) {
            QUI\System\Log::writeException($Throwable, QUI\System\Log::LEVEL_ERROR, [], 'MailJournal');
        }
    }

    /**
     * @throws Exception
     * @throws QUI\Exception
     * @throws \Exception
     * @throws Throwable
     */
    protected static function archiveMonth(
        string $month,
        string $tableOutbox,
        string $tableAttachments
    ): void {
        $Connection = QUI::getDataBaseConnection();
        $range = self::getMonthRange($month);

        $outboxColumns = [
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
            'archived',
            'archive_date'
        ];

        $outboxRows = $Connection->createQueryBuilder()
            ->select(...$outboxColumns)
            ->from(DoctrineUtils::quoteIdentifier($tableOutbox))
            ->where('COALESCE(send_date, create_date) >= :dateFrom')
            ->andWhere('COALESCE(send_date, create_date) < :dateTo')
            ->setParameter('dateFrom', $range['from'])
            ->setParameter('dateTo', $range['to'])
            ->orderBy('create_date', 'ASC')
            ->fetchAllAssociative();

        if (empty($outboxRows)) {
            return;
        }

        $mailIds = array_map(
            static fn(array $row) => (string)$row['id'],
            $outboxRows
        );

        $attachmentsRows = self::fetchAttachmentsByMailIds($mailIds, $tableAttachments);
        $file = self::getArchiveFilePath($month);

        self::writeArchiveSqlite($file, $outboxRows, $attachmentsRows);
        self::deleteByMailIds($mailIds, $tableOutbox, $tableAttachments);
    }

    /**
     * @param array<int, string> $mailIds
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    protected static function fetchAttachmentsByMailIds(array $mailIds, string $tableAttachments): array
    {
        if (!count($mailIds)) {
            return [];
        }

        return QUI::getQueryBuilder()
            ->select('id', 'mail_id', 'create_date', 'filename', 'mime_type', 'filesize', 'path')
            ->from(DoctrineUtils::quoteIdentifier($tableAttachments))
            ->where('mail_id IN (:mailIds)')
            ->setParameter('mailIds', $mailIds, ArrayParameterType::STRING)
            ->orderBy('create_date', 'ASC')
            ->fetchAllAssociative();
    }

    /**
     * @param array<int, string> $mailIds
     * @throws Exception
     */
    protected static function deleteByMailIds(array $mailIds, string $tableOutbox, string $tableAttachments): void
    {
        if (!count($mailIds)) {
            return;
        }

        $Connection = QUI::getDataBaseConnection();

        $Connection->createQueryBuilder()
            ->delete(DoctrineUtils::quoteIdentifier($tableAttachments))
            ->where('mail_id IN (:mailIds)')
            ->setParameter('mailIds', $mailIds, ArrayParameterType::STRING)
            ->executeStatement();

        $Connection->createQueryBuilder()
            ->delete(DoctrineUtils::quoteIdentifier($tableOutbox))
            ->where('id IN (:mailIds)')
            ->setParameter('mailIds', $mailIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * @throws QUI\Exception
     */
    protected static function getArchiveFilePath(string $month): string
    {
        $baseDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'archives/';
        File::mkdir($baseDir);

        $file = $baseDir . 'mail-journal-' . $month . '.sqlite';

        if (file_exists($file)) {
            $file = $baseDir . 'mail-journal-' . $month . '-' . date('YmdHis') . '.sqlite';
        }

        return $file;
    }

    protected static function getCutoffDate(): string
    {
        $Date = new DateTimeImmutable('first day of this month 00:00:00');
        return $Date->modify('-2 years')->format('Y-m-d H:i:s');
    }

    protected static function extractArchiveMonth(mixed $archiveDate): ?string
    {
        if (!is_string($archiveDate)) {
            return null;
        }

        $archiveDate = trim($archiveDate);

        if (preg_match('/^\d{4}-\d{2}-/', $archiveDate) !== 1) {
            return null;
        }

        return substr($archiveDate, 0, 7);
    }

    /**
     * @return array{from: string, to: string}
     * @throws \Exception
     */
    protected static function getMonthRange(string $month): array
    {
        $DateFrom = new DateTimeImmutable($month . '-01 00:00:00');
        $DateTo = $DateFrom->modify('+1 month');

        return [
            'from' => $DateFrom->format('Y-m-d H:i:s'),
            'to' => $DateTo->format('Y-m-d H:i:s')
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $outboxRows
     * @param array<int, array<string, mixed>> $attachmentsRows
     * @throws Throwable
     */
    protected static function writeArchiveSqlite(string $file, array $outboxRows, array $attachmentsRows): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Missing PHP extension pdo_sqlite');
        }

        $Pdo = new PDO('sqlite:' . $file);
        $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $Pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_journal_outbox (' .
            'id TEXT PRIMARY KEY,' .
            'create_date TEXT NOT NULL,' .
            'send_date TEXT NULL,' .
            'subject TEXT NULL,' .
            'body_html TEXT NULL,' .
            'body_text TEXT NULL,' .
            'mail_from TEXT NULL,' .
            'mail_from_name TEXT NULL,' .
            'mail_to TEXT NULL,' .
            'reply_to TEXT NULL,' .
            'mail_cc TEXT NULL,' .
            'mail_bcc TEXT NULL,' .
            'is_html INTEGER NOT NULL DEFAULT 1,' .
            'source_event TEXT NULL,' .
            'meta TEXT NULL,' .
            'archived INTEGER NOT NULL DEFAULT 0,' .
            'archive_date TEXT NULL' .
            ')'
        );

        $Pdo->exec(
            'CREATE TABLE IF NOT EXISTS mail_journal_outbox_attachments (' .
            'id TEXT PRIMARY KEY,' .
            'mail_id TEXT NOT NULL,' .
            'create_date TEXT NOT NULL,' .
            'filename TEXT NOT NULL,' .
            'mime_type TEXT NULL,' .
            'filesize INTEGER NULL,' .
            'path TEXT NULL' .
            ')'
        );

        $Pdo->exec('CREATE INDEX IF NOT EXISTS idx_mj_outbox_create_date ON mail_journal_outbox (create_date)');
        $Pdo->exec('CREATE INDEX IF NOT EXISTS idx_mj_outbox_send_date ON mail_journal_outbox (send_date)');
        $Pdo->exec('CREATE INDEX IF NOT EXISTS idx_mj_attachments_mail_id ON mail_journal_outbox_attachments (mail_id)');

        $StmtOutbox = $Pdo->prepare(
            'INSERT INTO mail_journal_outbox (' .
            'id, create_date, send_date, subject, body_html, body_text, mail_from, mail_from_name, mail_to, reply_to,' .
            'mail_cc, mail_bcc, is_html, source_event, meta, archived, archive_date' .
            ') VALUES (' .
            ':id, :create_date, :send_date, :subject, :body_html, :body_text, :mail_from, :mail_from_name, :mail_to, :reply_to,' .
            ':mail_cc, :mail_bcc, :is_html, :source_event, :meta, :archived, :archive_date' .
            ')'
        );

        $StmtAttachments = $Pdo->prepare(
            'INSERT INTO mail_journal_outbox_attachments (' .
            'id, mail_id, create_date, filename, mime_type, filesize, path' .
            ') VALUES (' .
            ':id, :mail_id, :create_date, :filename, :mime_type, :filesize, :path' .
            ')'
        );

        try {
            $Pdo->beginTransaction();

            foreach ($outboxRows as $row) {
                $StmtOutbox->execute([
                    ':id' => (string)($row['id'] ?? ''),
                    ':create_date' => self::normalizeArchiveValue($row['create_date'] ?? null),
                    ':send_date' => self::normalizeArchiveValue($row['send_date'] ?? null),
                    ':subject' => self::normalizeArchiveValue($row['subject'] ?? null),
                    ':body_html' => self::normalizeArchiveValue($row['body_html'] ?? null),
                    ':body_text' => self::normalizeArchiveValue($row['body_text'] ?? null),
                    ':mail_from' => self::normalizeArchiveValue($row['mail_from'] ?? null),
                    ':mail_from_name' => self::normalizeArchiveValue($row['mail_from_name'] ?? null),
                    ':mail_to' => self::normalizeArchiveValue($row['mail_to'] ?? null),
                    ':reply_to' => self::normalizeArchiveValue($row['reply_to'] ?? null),
                    ':mail_cc' => self::normalizeArchiveValue($row['mail_cc'] ?? null),
                    ':mail_bcc' => self::normalizeArchiveValue($row['mail_bcc'] ?? null),
                    ':is_html' => (int)($row['is_html'] ?? 0),
                    ':source_event' => self::normalizeArchiveValue($row['source_event'] ?? null),
                    ':meta' => self::normalizeArchiveValue($row['meta'] ?? null),
                    ':archived' => (int)($row['archived'] ?? 0),
                    ':archive_date' => self::normalizeArchiveValue($row['archive_date'] ?? null)
                ]);
            }

            foreach ($attachmentsRows as $row) {
                $StmtAttachments->execute([
                    ':id' => (string)($row['id'] ?? ''),
                    ':mail_id' => (string)($row['mail_id'] ?? ''),
                    ':create_date' => self::normalizeArchiveValue($row['create_date'] ?? null),
                    ':filename' => self::normalizeArchiveValue($row['filename'] ?? ''),
                    ':mime_type' => self::normalizeArchiveValue($row['mime_type'] ?? null),
                    ':filesize' => self::normalizeArchiveValue($row['filesize'] ?? null),
                    ':path' => self::normalizeArchiveValue($row['path'] ?? null)
                ]);
            }

            $Pdo->commit();
        } catch (Throwable $Throwable) {
            if ($Pdo->inTransaction()) {
                $Pdo->rollBack();
            }

            throw $Throwable;
        }
    }

    protected static function normalizeArchiveValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return (string)$value;
    }
}
