<?php

/**
 * This file contains package_quiqqer_mail-journal_ajax_backend_list
 */

use QUI\MailJournal\OutboxSearch;

QUI::getAjax()->registerFunction(
    'package_quiqqer_mail-journal_ajax_backend_list',
    static function ($params) {
        $searchParams = json_decode($params, true);

        if (!is_array($searchParams)) {
            $searchParams = [];
        }

        return OutboxSearch::searchForGrid($searchParams);
    },
    ['params'],
    ['Permission::checkAdminUser', 'quiqqer.mail-journal.view']
);
