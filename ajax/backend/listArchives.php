<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_listArchives
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_listArchives',
    static function () {
        $archiveDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'archives/';

        if (!is_dir($archiveDir)) {
            return [];
        }

        $files = scandir($archiveDir);

        if (!is_array($files)) {
            return [];
        }

        $result = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (!preg_match('/^mail-journal-\d{4}-\d{2}(?:-\d{14})?\.sql$/', $file)) {
                continue;
            }

            $path = $archiveDir . $file;

            if (!is_file($path)) {
                continue;
            }

            $result[] = [
                'file' => $file,
                'size' => (int)(filesize($path) ?: 0),
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($path))
            ];
        }

        usort(
            $result,
            static fn(array $a, array $b) => strcmp((string)$b['mtime'], (string)$a['mtime'])
        );

        return $result;
    },
    [],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.archive']
);
