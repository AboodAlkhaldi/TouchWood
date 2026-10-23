<?php

declare(strict_types=1);

// Why a provider's adapter could not read it, kept as a code and its values (ProviderReason, stage 5
// step 10): shown under "The provider said:" on the Sync screens, and in a refusal of the Provider form
// (errors.php's connection_failed rules).
return [
    'ODOO_TOO_MANY_PAGES' => "Odoo's items run past :pages pages.",
    'ODOO_NOT_HTTPS' => 'The address must start with https://.',
    'ODOO_TOO_MANY_CALLS' => 'Odoo answered 429 (too many calls).',
    'ODOO_TOO_MANY_CALLS_TWICE' => 'Odoo answered 429 (too many calls) twice.',
    'ODOO_REFUSED_TOKEN' => 'Odoo refused the token (401).',
    'ODOO_REFUSED_REQUEST' => 'Odoo refused the request (400).',
    'ODOO_FAILED' => 'Odoo failed (:status).',
    'ODOO_ANSWERED' => 'Odoo answered :status.',
    'ODOO_UNREACHABLE' => 'Odoo could not be reached.',
    'ODOO_UNREACHABLE_WITHIN' => 'Odoo could not be reached within :seconds seconds.',
    'ODOO_NO_ANSWER_WITHIN' => 'Odoo did not answer within :seconds seconds.',
    'ODOO_UNSENDABLE' => 'Odoo could not be asked: the address or the key holds a character that cannot be sent.',
    'ODOO_NOT_JSON' => "Odoo's answer is not as agreed: it is not JSON.",
    'ODOO_ITEMS_NOT_LIST' => "Odoo's answer is not as agreed: its items are not a list.",
    'ODOO_ITEM_WITHOUT_CODE' => "Odoo's answer is not as agreed: an item has no code.",
    'ODOO_EMPTY_PAGE_NAMES_NEXT' => "Odoo's answer is not as agreed: an empty page names a next one.",
    'ODOO_PAGES_REPEAT' => "Odoo's answer is not as agreed: its pages repeat.",
    'ODOO_NEXT_NOT_TEXT' => "Odoo's answer is not as agreed: its next page is not text.",
];
