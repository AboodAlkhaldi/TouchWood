<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SetRunPrices;

/**
 * One store's prices of a campaign (content.md §1.7 #4, #14; W4b's Prices tab): `{lines, targets, leftOut}` as
 * `PriceChoices` reads them, or null for none. `version` is the campaign's, as the form read it.
 */
final readonly class SetRunPrices
{
    /**
     * @param  array<array-key, mixed>|null  $prices
     */
    public function __construct(
        public string $runId,
        public int $version,
        public ?array $prices,
    ) {}
}
