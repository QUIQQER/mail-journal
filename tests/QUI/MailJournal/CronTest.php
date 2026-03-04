<?php

namespace QUITests\MailJournal;

use PDO;
use PHPUnit\Framework\TestCase;
use QUI\MailJournal\Cron;
use RuntimeException;
use Throwable;

class CronTest extends TestCase
{
    public function testMonthRangeBuildsInclusiveExclusiveBounds(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'getMonthRange');
        $method->setAccessible(true);

        $range = $method->invoke(null, '2023-11');

        $this->assertSame('2023-11-01 00:00:00', $range['from']);
        $this->assertSame('2023-12-01 00:00:00', $range['to']);
    }

    public function testNormalizeArchiveValueKeepsStringsAndNull(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'normalizeArchiveValue');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, null));
        $this->assertSame('foo', $method->invoke(null, 'foo'));
        $this->assertSame('42', $method->invoke(null, 42));
        $this->assertSame('1', $method->invoke(null, true));
    }

    public function testGetArchiveFilePathUsesSqliteExtension(): void
    {
        $method = new \ReflectionMethod(Cron::class, 'getArchiveFilePath');
        $method->setAccessible(true);

        $file = $method->invoke(
            null,
            '2026-01'
        );

        $this->assertMatchesRegularExpression('/mail-journal-2026-01(?:-\d{14})?\.sqlite$/', $file);
    }

    public function testWriteArchiveSqliteCreatesTablesAndRows(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not available');
        }

        $method = new \ReflectionMethod(Cron::class, 'writeArchiveSqlite');
        $method->setAccessible(true);

        $file = tempnam(sys_get_temp_dir(), 'mj-archive-');

        if ($file === false) {
            throw new RuntimeException('Could not create temp file');
        }

        unlink($file);
        $file .= '.sqlite';

        $outboxRows = [[
            'id' => 'mail-1',
            'create_date' => '2023-11-10 08:00:00',
            'send_date' => '2023-11-10 08:01:00',
            'subject' => 'Subject 1',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'mail_from' => 'from@example.com',
            'mail_from_name' => 'Sender',
            'mail_to' => '[["to@example.com","To"]]',
            'reply_to' => null,
            'mail_cc' => null,
            'mail_bcc' => null,
            'is_html' => 1,
            'source_event' => 'event.test',
            'meta' => '{"k":"v"}',
            'archived' => 1,
            'archive_date' => '2026-03-04 10:00:00'
        ]];

        $attachmentsRows = [[
            'id' => 'att-1',
            'mail_id' => 'mail-1',
            'create_date' => '2023-11-10 08:00:00',
            'filename' => 'a.txt',
            'mime_type' => 'text/plain',
            'filesize' => 12,
            'path' => '/tmp/a.txt'
        ]];

        try {
            $method->invoke(null, $file, $outboxRows, $attachmentsRows);

            $this->assertFileExists($file);

            $Pdo = new PDO('sqlite:' . $file);
            $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $outboxCount = (int)$Pdo->query('SELECT COUNT(*) FROM mail_journal_outbox')->fetchColumn();
            $attachmentsCount = (int)$Pdo->query(
                'SELECT COUNT(*) FROM mail_journal_outbox_attachments'
            )->fetchColumn();

            $this->assertSame(1, $outboxCount);
            $this->assertSame(1, $attachmentsCount);

            $subject = (string)$Pdo->query(
                "SELECT subject FROM mail_journal_outbox WHERE id = 'mail-1'"
            )->fetchColumn();

            $this->assertSame('Subject 1', $subject);
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
