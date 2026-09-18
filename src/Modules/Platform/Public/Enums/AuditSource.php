<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * Where an audited change came from. Worked out by Platform, never passed in (owner's decision,
 * 2026-09-18), so no module can forget or mislabel it.
 */
enum AuditSource: string
{
    /** A web request: the storefront or the admin panel. */
    case Web = 'WEB';
    /** A web request made by an integration, e.g. a payment provider's webhook. */
    case Integration = 'INTEGRATION';
    /** An artisan command, e.g. creating a store. */
    case Console = 'CONSOLE';
    /** A queued job or scheduled task. Whose action queued it is recorded separately. */
    case Job = 'JOB';
    /** History brought over from the old system, with its real date. Only through the import method. */
    case Import = 'IMPORT';
}
