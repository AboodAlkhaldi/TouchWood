<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\ShopperLineTone;

/**
 * One line under the shop's header, for the customer signed in (access.md amendment 50).
 */
final readonly class ShopperLineDto
{
    /**
     * @param  string  $text  already in the page's language: the module that writes it owns the words
     * @param  string  $routeName  the named shop route the line links to, under /{store}/{locale}
     */
    public function __construct(
        public string $text,
        public string $routeName,
        public ShopperLineTone $tone,
    ) {}
}
