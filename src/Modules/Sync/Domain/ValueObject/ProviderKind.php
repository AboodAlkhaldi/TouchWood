<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\ValueObject;

/**
 * The providers Sync can read (sync.md §1.2, §5 `connections_provider`): Odoo, the one wanted. Stored as
 * its string, as every stored kind is.
 */
enum ProviderKind: string
{
    case Odoo = 'ODOO';
}
