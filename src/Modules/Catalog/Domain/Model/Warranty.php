<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Domain\ValueObject\WarrantyPeriod;

/**
 * A warranty a product may carry — at most one, the same in every store (catalog.md §1.9): a name
 * and its terms in both languages, the terms as simple formatted text (at most 5,000 characters,
 * §9.3 #2), and how long it lasts, in months or for life.
 */
final class Warranty
{
    public const int NAME_MAX = 100;

    public const int TERMS_MAX = 5000;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private LocalizedName $name,
        private StructuredText $termsAr,
        private StructuredText $termsEn,
        private WarrantyPeriod $period,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    public static function add(string $id, LocalizedName $name, StructuredText $termsAr, StructuredText $termsEn, WarrantyPeriod $period): self
    {
        return new self($id, $name, $termsAr, $termsEn, $period, true);
    }

    public static function reconstitute(string $id, LocalizedName $name, StructuredText $termsAr, StructuredText $termsEn, WarrantyPeriod $period, bool $isActive): self
    {
        return new self($id, $name, $termsAr, $termsEn, $period, $isActive);
    }

    public function edit(LocalizedName $name, StructuredText $termsAr, StructuredText $termsEn, WarrantyPeriod $period): void
    {
        $before = $this->snapshot();
        $this->name = $name;
        $this->termsAr = $termsAr;
        $this->termsEn = $termsEn;
        $this->period = $period;

        foreach ($this->snapshot() as $column => $now) {
            $this->changes->record($column, $before[$column], $now);
        }
    }

    public function deactivate(): void
    {
        $this->changes->record('is_active', $this->isActive, false);
        $this->isActive = false;
    }

    public function activate(): void
    {
        $this->changes->record('is_active', $this->isActive, true);
        $this->isActive = true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): LocalizedName
    {
        return $this->name;
    }

    public function termsAr(): StructuredText
    {
        return $this->termsAr;
    }

    public function termsEn(): StructuredText
    {
        return $this->termsEn;
    }

    public function period(): WarrantyPeriod
    {
        return $this->period;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->name->ar,
            'name_en' => $this->name->en,
            'terms_ar' => $this->termsAr->toJson(),
            'terms_en' => $this->termsEn->toJson(),
            'period_months' => $this->period->months,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function pullChanges(): array
    {
        return $this->changes->pull();
    }
}
