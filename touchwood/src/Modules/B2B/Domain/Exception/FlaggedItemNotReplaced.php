<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending a draft while an item the last rejection flagged is unchanged (b2b.md §1.2, §7,
 * amendment 4): a field still holding what was sent, or a document with no new file.
 *
 * The person is told one general sentence, the same for every item (owner, 2026-09-28, amendment
 * 6(e)); the draft already shows each marked item where it is. So it names nothing in its
 * translated message. The item — a field's name or a document type's id — is in getMessage() only,
 * for the log.
 */
final class FlaggedItemNotReplaced extends B2BError
{
    public function __construct(public readonly string $item = '')
    {
        parent::__construct("An item marked in the last decision has not been replaced ({$item}).");
    }

    public function type(): string
    {
        return 'b2b.flagged_item_not_replaced';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
