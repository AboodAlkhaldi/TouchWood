<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A store's format without the region, city or district field, or with one shorter than a place's name may
 * be (owner, 2026-10-29, frontend.md §8.4 Q12): the places a customer picks fill those three keys
 * (AddressPlaceRules), and a format refuses a key it does not have or a value too long for it.
 */
final class AddressFormatNeedsPlaces extends AccessError
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('A store\'s address format keeps '.implode(', ', $missing).'.');
    }

    public function type(): string
    {
        return 'access.address_format_needs_places';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['missing' => implode(', ', $this->missing)];
    }
}
