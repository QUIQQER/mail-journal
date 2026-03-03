<?php

namespace QUI\MailJournal;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use QUI;
use QUI\Cron\Manager;
use QUI\Utils\System\File;
use RuntimeException;
use Throwable;

use function array_map;
use function count;
use function date;
use function file_exists;
use function file_put_contents;
use function implode;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function str_replace;

class Cron
{
    protected const TABLE_OUTBOX = 'mail_journal_outbox';
    protected const TABLE_ATTACHMENTS = 'mail_journal_outbox_attachments';

    /**
     * Archive all outbox mails older than 2 years into monthly SQL files.
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
            $months = $Connection->createQueryBuilder()
                ->select("DATE_FORMAT(COALESCE(send_date, create_date), '%Y-%m') AS archive_month")
                ->from($tableOutbox)
                ->where('COALESCE(send_date, create_date) < :cutoffDate')
                ->setParameter('cutoffDate', $cutoffDate)
                ->groupBy('archive_month')
                ->orderBy('archive_month', 'ASC')
                ->fetchFirstColumn();

            if (empty($months)) {
                return;
            }

            foreach ($months as $month) {
                if (!is_string($month) || $month === '') {
                    continue;
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
            ->from($tableOutbox)
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
        $sql = self::buildArchiveSql($tableOutbox, $tableAttachments, $outboxColumns, $outboxRows, $attachmentsRows);

        if (file_put_contents($file, $sql) === false) {
            throw new RuntimeException('Could not write archive file: ' . $file);
        }

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

        $placeholders = [];
        $binds = [];

        foreach ($mailIds as $i => $mailId) {
            $placeholder = ':id' . $i;
            $placeholders[] = $placeholder;
            $binds[$placeholder] = $mailId;
        }

        $inSql = implode(', ', $placeholders);
        $Connection = QUI::getDataBaseConnection();
        $Stmt = $Connection->prepare(
            'SELECT id, mail_id, create_date, filename, mime_type, filesize, path ' .
            'FROM `' . $tableAttachments . '` WHERE mail_id IN (' . $inSql . ') ORDER BY create_date ASC'
        );

        foreach ($binds as $name => $value) {
            $Stmt->bindValue($name, $value);
        }

        return $Stmt->executeQuery()->fetchAllAssociative();
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

        $placeholders = [];
        $binds = [];

        foreach ($mailIds as $i => $mailId) {
            $placeholder = ':id' . $i;
            $placeholders[] = $placeholder;
            $binds[$placeholder] = $mailId;
        }

        $inSql = implode(', ', $placeholders);
        $Connection = QUI::getDataBaseConnection();

        $StmtAttachments = $Connection->prepare(
            'DELETE FROM `' . $tableAttachments . '` WHERE mail_id IN (' . $inSql . ')'
        );

        foreach ($binds as $name => $value) {
            $StmtAttachments->bindValue($name, $value);
        }

        $StmtAttachments->executeStatement();

        $StmtOutbox = $Connection->prepare(
            'DELETE FROM `' . $tableOutbox . '` WHERE id IN (' . $inSql . ')'
        );

        foreach ($binds as $name => $value) {
            $StmtOutbox->bindValue($name, $value);
        }

        $StmtOutbox->executeStatement();
    }

    /**
     * @throws QUI\Exception
     */
    protected static function getArchiveFilePath(string $month): string
    {
        $baseDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'archives/';
        File::mkdir($baseDir);

        $file = $baseDir . 'mail-journal-' . $month . '.sql';

        if (file_exists($file)) {
            $file = $baseDir . 'mail-journal-' . $month . '-' . date('YmdHis') . '.sql';
        }

        return $file;
    }

    protected static function getCutoffDate(): string
    {
        $Date = new DateTimeImmutable('first day of this month 00:00:00');
        return $Date->modify('-2 years')->format('Y-m-d H:i:s');
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
     * @param array<int, string> $outboxColumns
     * @param array<int, array<string, mixed>> $outboxRows
     * @param array<int, array<string, mixed>> $attachmentsRows
     */
    protected static function buildArchiveSql(
        string $tableOutbox,
        string $tableAttachments,
        array $outboxColumns,
        array $outboxRows,
        array $attachmentsRows
    ): string {
        $sql = '-- Mail Journal archive generated at ' . date('c') . "\n";
        $sql .= "START TRANSACTION;\n\n";
        $sql .= self::buildInsertSql($tableOutbox, $outboxColumns, $outboxRows) . "\n\n";

        if (!empty($attachmentsRows)) {
            $attachmentsColumns = [
                'id',
                'mail_id',
                'create_date',
                'filename',
                'mime_type',
                'filesize',
                'path'
            ];

            $sql .= self::buildInsertSql($tableAttachments, $attachmentsColumns, $attachmentsRows) . "\n\n";
        }

        $sql .= "COMMIT;\n";

        return $sql;
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    protected static function buildInsertSql(string $table, array $columns, array $rows): string
    {
        $columnSql = '`' . implode('`, `', $columns) . '`';
        $values = [];

        foreach ($rows as $row) {
            $rowValues = [];

            foreach ($columns as $column) {
                $rowValues[] = self::toSqlValue($row[$column] ?? null);
            }

            $values[] = '(' . implode(', ', $rowValues) . ')';
        }

        return 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES' . "\n" .
            implode(",\n", $values) . ';';
    }

    protected static function toSqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_numeric($value)) {
            return (string)$value;
        }

        if (!is_string($value)) {
            $value = (string)$value;
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
