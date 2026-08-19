<?php

/**
 * Manual test helper cleanup for mail-journal archive cron.
 *
 * What it does:
 * - removes all outbox and attachment rows created by seed_archive_test_data.php
 * - targets only IDs with prefix "mj-archive-test-"
 *
 * Scope:
 * - does not touch production rows unless they use the same dedicated test prefix
 */

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use QUI\Utils\Doctrine as DoctrineUtils;

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

echo "This will delete seeded test rows from mail-journal tables. Continue? [y/N]: ";
$confirm = function_exists('readline') ? readline() : fgets(STDIN);
$confirm = strtolower(trim((string)$confirm));

if ($confirm !== 'y' && $confirm !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

$Connection = QUI::getDataBaseConnection();
$tableOutbox = DoctrineUtils::quoteIdentifier(QUI::getDBTableName('mail_journal_outbox'));
$tableAttachments = DoctrineUtils::quoteIdentifier(QUI::getDBTableName('mail_journal_outbox_attachments'));

$prefix = 'mj-archive-test-';
$ids = [
    $prefix . 'old-a',
    $prefix . 'old-b',
    $prefix . 'boundary',
    $prefix . 'new'
];

$deletedAttachments = $Connection->createQueryBuilder()
    ->delete($tableAttachments)
    ->where('mail_id IN (:mailIds)')
    ->setParameter('mailIds', $ids, ArrayParameterType::STRING)
    ->executeStatement();

$deletedOutbox = $Connection->createQueryBuilder()
    ->delete($tableOutbox)
    ->where('id IN (:mailIds)')
    ->setParameter('mailIds', $ids, ArrayParameterType::STRING)
    ->executeStatement();

echo "Cleanup done.\n";
echo "Deleted outbox rows: " . (int)$deletedOutbox . "\n";
echo "Deleted attachment rows: " . (int)$deletedAttachments . "\n";
