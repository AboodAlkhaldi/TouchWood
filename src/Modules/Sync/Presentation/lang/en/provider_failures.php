<?php

declare(strict_types=1);

// Why a provider's adapter could not read it, kept as a code and its values (ProviderReason, stage 5
// step 10): shown under "The provider said:" on the Sync screens, and in a refusal of the Provider form
// (errors.php's connection_failed rules).
return [
    'ODOO_NOT_HTTPS' => 'The address must start with https://.',
    'ODOO_TOO_MANY_CALLS' => 'Odoo answered 429 (too many calls).',
    'ODOO_TOO_MANY_CALLS_TWICE' => 'Odoo answered 429 (too many calls) twice.',
    'ODOO_REFUSED_TOKEN' => 'Odoo refused the token (401).',
    'ODOO_REFUSED_ACCESS' => "Odoo refused access (403): the token's user may not read products.",
    'ODOO_REFUSED_REQUEST' => 'Odoo refused the request (400).',
    'ODOO_FAILED' => 'Odoo failed (:status).',
    'ODOO_ANSWERED' => 'Odoo answered :status.',
    'ODOO_UNREACHABLE' => 'Odoo could not be reached.',
    'ODOO_UNREACHABLE_WITHIN' => 'Odoo could not be reached within :seconds seconds.',
    'ODOO_NO_ANSWER_WITHIN' => 'Odoo did not answer within :seconds seconds.',
    'ODOO_UNSENDABLE' => 'Odoo could not be asked: the address or the key holds a character that cannot be sent.',
    'ODOO_NOT_JSON' => "Odoo's answer is not as agreed: it is not JSON.",
    'ODOO_ANSWER_NOT_LIST' => "Odoo's answer is not as agreed: it is not a list.",
    'ODOO_ITEM_WITHOUT_ID' => "Odoo's answer is not as agreed: an item has no whole-number id.",
];
