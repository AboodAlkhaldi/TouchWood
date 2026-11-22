<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

/**
 * A campaign run reaching some of the same sizes in the same dates (pricing.md §9.6 #7): its run's id, its
 * lines, its dates as ISO 8601, the sizes it shares, and its short description in the reader's language
 * ("A campaign": its name is Content's). It never always wins.
 */
final readonly class OverlappingCampaign
{
    /**
     * @param  list<string>  $sharedVariantIds
     */
    public function __construct(
        public string $id,
        public string $lines,
        public string $startsAt,
        public ?string $endsAt,
        public array $sharedVariantIds,
        public ?string $description = null,
    ) {}
}
