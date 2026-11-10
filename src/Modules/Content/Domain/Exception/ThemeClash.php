<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two themes in one store at once (content.md §1.8, §7), naming the other theme, the store and its dates.
 */
final class ThemeClash extends ContentError
{
    /**
     * @param  string  $theme  the other theme's name
     * @param  string  $store  the store's name
     * @param  string  $starts  its start
     * @param  string  $ends  its end
     */
    public function __construct(
        public readonly string $theme,
        public readonly string $store,
        public readonly string $starts,
        public readonly string $ends,
    ) {
        parent::__construct("The theme {$theme} runs in {$store} then.");
    }

    public function type(): string
    {
        return 'content.theme_clash';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['theme' => $this->theme, 'store' => $this->store, 'starts' => $this->starts, 'ends' => $this->ends];
    }
}
