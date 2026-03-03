<?php

declare(strict_types=1);

/**
 * Manual test helper for mail-journal archive cron.
 *
 * What it does:
 * - inserts deterministic test mails with prefix "mj-archive-test-"
 * - creates 2 mails older than 2 years (should be archived/deleted by cron)
 * - creates 1 boundary mail exactly at cutoff (should stay)
 * - creates 1 recent mail (should stay)
 * - inserts sample attachment rows for one old and one recent mail
 *
 * Safe usage:
 * - this script only creates rows with the dedicated test prefix
 * - use cleanup_archive_test_data.php to remove all created rows
 */

if (PHP_SAPI !== 'cli') {
    echo "This script can only be executed via CLI.\n";
    exit(1);
}

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

if (!defined('QUIQQER_CONSOLE')) {
    define('QUIQQER_CONSOLE', true);
}

require_once __DIR__ . '/../../../../../bootstrap.php';

echo "This will insert test rows into mail-journal tables. Continue? [y/N]: ";
$confirm = function_exists('readline') ? readline() : fgets(STDIN);
$confirm = strtolower(trim((string)$confirm));

if ($confirm !== 'y' && $confirm !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

$Connection = QUI::getDataBaseConnection();
$tableOutbox = QUI::getDBTableName('mail_journal_outbox');
$tableAttachments = QUI::getDBTableName('mail_journal_outbox_attachments');

$now = new DateTimeImmutable();
$monthStart = new DateTimeImmutable('first day of this month 00:00:00');
$cutoff = $monthStart->modify('-2 years');

$oldDateA = $cutoff->modify('-40 days')->format('Y-m-d H:i:s');
$oldDateB = $cutoff->modify('-70 days')->format('Y-m-d H:i:s');
$boundaryDate = $cutoff->format('Y-m-d H:i:s');
$newDate = $now->modify('-30 days')->format('Y-m-d H:i:s');

$prefix = 'mj-archive-test-';
$Connection->executeStatement(
    'DELETE FROM `' . $tableAttachments . '` WHERE mail_id LIKE :prefix',
    ['prefix' => $prefix . '%']
);
$Connection->executeStatement(
    'DELETE FROM `' . $tableOutbox . '` WHERE id LIKE :prefix',
    ['prefix' => $prefix . '%']
);

$rows = [
    [
        'id' => $prefix . 'old-a',
        'create_date' => $oldDateA,
        'send_date' => $oldDateA,
        'subject' => 'Archive candidate A',
        'body_html' => '<p>old a</p>',
        'body_text' => 'old a',
        'mail_from' => 'from@example.com',
        'mail_from_name' => 'Sender',
        'mail_to' => '[["to@example.com","Receiver"]]',
        'reply_to' => null,
        'mail_cc' => null,
        'mail_bcc' => null,
        'is_html' => 1,
        'source_event' => 'test.seed',
        'meta' => '{"seed":true,"type":"old-a"}',
        'archived' => 0,
        'archive_date' => null
    ],
    [
        'id' => $prefix . 'old-b',
        'create_date' => $oldDateB,
        'send_date' => $oldDateB,
        'subject' => 'Archive candidate B',
        'body_html' => '<p>old b</p>',
        'body_text' => 'old b',
        'mail_from' => 'from@example.com',
        'mail_from_name' => 'Sender',
        'mail_to' => '[["to@example.com","Receiver"]]',
        'reply_to' => null,
        'mail_cc' => null,
        'mail_bcc' => null,
        'is_html' => 1,
        'source_event' => 'test.seed',
        'meta' => '{"seed":true,"type":"old-b"}',
        'archived' => 0,
        'archive_date' => null
    ],
    [
        'id' => $prefix . 'boundary',
        'create_date' => $boundaryDate,
        'send_date' => $boundaryDate,
        'subject' => 'Boundary candidate (must stay)',
        'body_html' => '<p>boundary</p>',
        'body_text' => 'boundary',
        'mail_from' => 'from@example.com',
        'mail_from_name' => 'Sender',
        'mail_to' => '[["to@example.com","Receiver"]]',
        'reply_to' => null,
        'mail_cc' => null,
        'mail_bcc' => null,
        'is_html' => 1,
        'source_event' => 'test.seed',
        'meta' => '{"seed":true,"type":"boundary"}',
        'archived' => 0,
        'archive_date' => null
    ],
    [
        'id' => $prefix . 'new',
        'create_date' => $newDate,
        'send_date' => $newDate,
        'subject' => 'New candidate (must stay)',
        'body_html' => '<p>new</p>',
        'body_text' => 'new',
        'mail_from' => 'from@example.com',
        'mail_from_name' => 'Sender',
        'mail_to' => '[["to@example.com","Receiver"]]',
        'reply_to' => null,
        'mail_cc' => null,
        'mail_bcc' => null,
        'is_html' => 1,
        'source_event' => 'test.seed',
        'meta' => '{"seed":true,"type":"new"}',
        'archived' => 0,
        'archive_date' => null
    ]
];

foreach ($rows as $row) {
    $Connection->insert($tableOutbox, $row);
}

$Connection->insert($tableAttachments, [
    'id' => $prefix . 'att-old-a',
    'mail_id' => $prefix . 'old-a',
    'create_date' => $oldDateA,
    'filename' => 'old-a.pdf',
    'mime_type' => 'application/pdf',
    'filesize' => 123,
    'path' => '/tmp/old-a.pdf'
]);

$Connection->insert($tableAttachments, [
    'id' => $prefix . 'att-new',
    'mail_id' => $prefix . 'new',
    'create_date' => $newDate,
    'filename' => 'new.pdf',
    'mime_type' => 'application/pdf',
    'filesize' => 456,
    'path' => '/tmp/new.pdf'
]);

echo "Seed done.\n";
echo "Outbox table: " . $tableOutbox . "\n";
echo "Attachment table: " . $tableAttachments . "\n";
echo "Cutoff (<): " . $cutoff->format('Y-m-d H:i:s') . "\n";
echo "Inserted IDs:\n";
foreach ($rows as $row) {
    echo "- " . $row['id'] . " (" . $row['send_date'] . ")\n";
}
