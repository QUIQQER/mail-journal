<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_get
 */

use QUI\MailJournal\MailRepository;

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_get',
    static function ($mailId) {
        $Repository = new MailRepository();
        $Mail = $Repository->getById((string)$mailId);

        if ($Mail === null) {
            return [];
        }

        return $Mail->toArray();
    },
    ['mailId'],
    'Permission::checkAdminUser'
);
