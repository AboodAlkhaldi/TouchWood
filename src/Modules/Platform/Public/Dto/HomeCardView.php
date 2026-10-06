<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * A card the reader may see, with what it shows for the scope asked (platform.md §2.6).
 */
final readonly class HomeCardView
{
    public function __construct(
        public HomeCardDto $card,
        public HomeCardData $data,
    ) {}
}
