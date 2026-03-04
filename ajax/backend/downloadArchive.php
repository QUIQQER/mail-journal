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

        if (!preg_match('/^mail-journal-\d{4}-\d{2}(?:-\d{14})?\.(?:sqlite|sql)$/', $file)) {
            return;
        }

        $archiveDir = QUI::getPackage('quiqqer/mail-journal')->getVarDir() . 'archives/';
        $path = $archiveDir . $file;

        if (!is_file($path)) {
            return;
        }

        $downloadFile = $file;

        if (str_starts_with($file, 'mail-journal-')) {
            $host = (string)QUI::conf('globals', 'host');
            $host = trim($host);

            if ($host !== '') {
                if (str_contains($host, '://')) {
                    $parsedHost = parse_url($host, PHP_URL_HOST);

                    if (is_string($parsedHost) && $parsedHost !== '') {
                        $host = $parsedHost;
                    }
                }

                if (str_contains($host, '/')) {
                    $host = explode('/', $host)[0];
                }

                $host = preg_replace('/:\d+$/', '', $host) ?: '';
                $host = preg_replace('/^www\./i', '', $host) ?: '';
                $host = strtolower($host);
                $host = str_replace('.', '-', $host);
                $host = preg_replace('/[^a-z0-9.-]+/', '-', $host) ?: '';
                $host = trim($host, '-.');

                if ($host !== '') {
                    $downloadFile = $host . '-' . $file;
                }
            }
        }

        header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        $extension = strtolower((string)pathinfo($downloadFile, PATHINFO_EXTENSION));
        $contentType = $extension === 'sqlite' ? 'application/vnd.sqlite3' : 'application/sql';

        header('Content-type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadFile . '"');

        readfile($path);
        exit;
    },
    ['file'],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.archive']
);
