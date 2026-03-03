<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_delete
 */

use QUI\MailJournal\MailRepository;

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_delete',
    static function ($mailId) {
        $Repository = new MailRepository();

        if (is_array($mailId)) {
            return $Repository->deleteByIds($mailId);
        }

        if (is_string($mailId)) {
            $mailId = trim($mailId);

            if ($mailId === '') {
                return 0;
            }

            $decoded = json_decode($mailId, true);

            if (is_array($decoded)) {
                return $Repository->deleteByIds($decoded);
            }

            if (str_contains($mailId, ',')) {
                return $Repository->deleteByIds(explode(',', $mailId));
            }

            return $Repository->deleteById($mailId) ? 1 : 0;
        }

        return 0;
    },
    ['mailId'],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.delete']
);
