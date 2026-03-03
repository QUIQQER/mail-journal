<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_downloadArchive
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_downloadArchive',
    static function ($file) {
        if (!is_string($file)) {
            return;
        }

        $file = basename($file);

        if (!preg_match('/^mail-journal-\d{4}-\d{2}(?:-\d{14})?\.sql$/', $file)) {
            return;
        }

        $archiveDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'archives/';
        $path = $archiveDir . $file;

        if (!is_file($path)) {
            return;
        }

        QUI\Utils\System\File::downloadHeader($path, true, $file);
    },
    ['file'],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.archive']
);
