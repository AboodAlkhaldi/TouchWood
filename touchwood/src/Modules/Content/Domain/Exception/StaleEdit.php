<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A save over a newer version (content.md §1.1 rule 12, §7), naming who saved it and when; what was typed
 * stays on the screen.
 */
final class StaleEdit extends ContentError
{
    /**
     * @param  string  $by  who saved the newer version, by name
     * @param  string  $at  when, as the screen shows it
     */
    public function __construct(
        public readonly string $by,
        public readonly string $at,
    ) {
        parent::__construct("Saved meanwhile by {$by} at {$at}.");
    }

    public function type(): string
    {
        return 'content.stale_edit';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['by' => $this->by, 'at' => $this->at];
    }
}
