<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One line of a home card's short list (platform.md §2.6) - a company that waits, say.
 */
final readonly class HomeRow
{
    /**
     * @param  string  $label  already in words: a name
     * @param  string|null  $detail  already in words: its store, say
     * @param  string|null  $at  an ISO-8601 moment the page writes as the page's moments are written
     * @param  string|null  $href  the screen it opens
     */
    public function __construct(
        public string $label,
        public ?string $detail = null,
        public ?string $at = null,
        public ?string $href = null,
    ) {}
}
