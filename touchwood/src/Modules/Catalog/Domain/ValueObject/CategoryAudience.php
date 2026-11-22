<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

/**
 * **Who sees a category in one store** (catalog.md §1.13, amendment 14): its two switches,
 * Individuals and Companies. A category nobody switched is seen by both (14(b)): that is `both()`,
 * and what a store with no row for it answers.
 */
final readonly class CategoryAudience
{
    private function __construct(
        public bool $forIndividuals,
        public bool $forCompanies,
    ) {}

    public static function both(): self
    {
        return new self(true, true);
    }

    public static function of(bool $forIndividuals, bool $forCompanies): self
    {
        return new self($forIndividuals, $forCompanies);
    }

    /**
     * These switches with the ones given flipped to their value; a switch given as null stays as it is.
     */
    public function with(?bool $forIndividuals, ?bool $forCompanies): self
    {
        return new self($forIndividuals ?? $this->forIndividuals, $forCompanies ?? $this->forCompanies);
    }

    public function equals(self $other): bool
    {
        return $this->forIndividuals === $other->forIndividuals && $this->forCompanies === $other->forCompanies;
    }

    /**
     * @return array{for_individuals: bool, for_companies: bool}
     */
    public function snapshot(): array
    {
        return ['for_individuals' => $this->forIndividuals, 'for_companies' => $this->forCompanies];
    }

    /**
     * The switches that differ in $now, as they were here — what an audit entry reads from.
     *
     * @return array<string, bool>
     */
    public function changedIn(self $now): array
    {
        return array_diff_assoc($this->snapshot(), $now->snapshot());
    }
}
