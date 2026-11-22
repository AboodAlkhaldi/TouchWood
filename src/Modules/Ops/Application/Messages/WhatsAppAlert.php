<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

/**
 * The two critical alerts that go to a Super Admin by WhatsApp (ops.md §9.8 point 9, W1), each a utility template of
 * our own fixed words, approved by Meta once. The template's name is a setting of the server (`.env`).
 */
enum WhatsAppAlert
{
    /** The payment gateway's key expires in one day (S5). */
    case KeyExpiring;

    /** The payment gateway refused the key (S5b). */
    case KeyRefused;
}
