<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetCategoryAudiences;

/**
 * Who sees a category in one store (catalog.md §1.13, §3, amendment 14): its Individuals switch, its
 * Companies switch, or both — a switch left null stays as it is. The category and everything under it
 * take what is switched (14(d)).
 */
final readonly class SetCategoryAudiences
{
    public function __construct(
        public string $storeId,
        public string $categoryId,
        public ?bool $forIndividuals = null,
        public ?bool $forCompanies = null,
    ) {}
}
