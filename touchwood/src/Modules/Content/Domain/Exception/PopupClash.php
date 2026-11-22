<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two pop-ups in one store at once (content.md §1.10, §7), naming the other pop-up, the store and its dates.
 */
final class PopupClash extends ContentError
{
    /**
     * @param  string  $popup  the other pop-up's name
     * @param  string  $store  the store's name
     * @param  string  $starts  its start
     * @param  string  $ends  its end
     */
    public function __construct(
        public readonly string $popup,
        public readonly string $store,
        public readonly string $starts,
        public readonly string $ends,
    ) {
        parent::__construct("The pop-up {$popup} runs in {$store} then.");
    }

    public function type(): string
    {
        return 'content.popup_clash';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['popup' => $this->popup, 'store' => $this->store, 'starts' => $this->starts, 'ends' => $this->ends];
    }
}
