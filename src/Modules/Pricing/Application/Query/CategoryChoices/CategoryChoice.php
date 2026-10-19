<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\CategoryChoices;

/**
 * A category the dialog offers: its id, and the path to it from the top in the page's language ("Kitchens",
 * "Cabinets").
 */
final readonly class CategoryChoice
{
    /**
     * @param  list<string>  $path
     */
    public function __construct(
        public string $id,
        public array $path,
    ) {}
}
