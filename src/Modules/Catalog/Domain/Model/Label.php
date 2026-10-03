<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Model;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\LabelTone;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Catalog\Domain\ValueObject\LocalizedName;

/**
 * A custom label — «الشارات» in Arabic — on product cards, "New" or "Clearance" (catalog.md §1.8):
 * one global list, which each store attaches to its products itself (step 4).
 *
 * - **Drawn with Geist's Badge, its colour following Geist's meanings** (amendment 1(e)): a tone.
 * - **One or two words** in each language (amendment 1(f), after Geist), at most 30 characters.
 * - Its position is the order labels take on a card; every attached label shows (amendment 1(g)).
 */
final class Label
{
    public const int NAME_MAX = 30;

    public const int MAX_WORDS = 2;

    private ChangeLog $changes;

    private function __construct(
        private readonly string $id,
        private LocalizedName $name,
        private LabelTone $tone,
        private int $position,
        private bool $isActive,
    ) {
        $this->changes = new ChangeLog;
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function add(string $id, LocalizedName $name, LabelTone $tone, int $position): self
    {
        self::checkWords($name);

        return new self($id, $name, $tone, ListPosition::check($position), true);
    }

    public static function reconstitute(string $id, LocalizedName $name, LabelTone $tone, int $position, bool $isActive): self
    {
        return new self($id, $name, $tone, $position, $isActive);
    }

    /**
     * @throws InvalidCatalogAttribute
     */
    public function edit(LocalizedName $name, LabelTone $tone, int $position): void
    {
        self::checkWords($name);
        $before = $this->snapshot();
        $this->name = $name;
        $this->tone = $tone;
        $this->position = ListPosition::check($position);

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

    public function tone(): LabelTone
    {
        return $this->tone;
    }

    public function position(): int
    {
        return $this->position;
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
            'tone' => $this->tone->value,
            'position' => $this->position,
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

    /**
     * @throws InvalidCatalogAttribute
     */
    private static function checkWords(LocalizedName $name): void
    {
        foreach (['name_ar' => $name->ar, 'name_en' => $name->en] as $attribute => $text) {
            // The name is already trimmed and on one line: words are what the spaces separate.
            if (count(preg_split('/\s+/u', $text) ?: []) > self::MAX_WORDS) {
                throw new InvalidCatalogAttribute($attribute, 'one or two words');
            }
        }
    }
}
