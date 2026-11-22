<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use Modules\Platform\Domain\Exception\InvalidPlace;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Enums\PlaceKind;
use Shared\Domain\Text\LatinDigits;

/**
 * A region, a city or a district of an address country (platform.md, stage 7 (a)). A region has no
 * parent, a city's parent is a region, a district's a city - in the same country. Never deleted,
 * only retired, and restored (shipping.md §9.3 #4; stage 7 step 1, Q3).
 */
final class Place
{
    public const int MAX_NAME_LENGTH = 100;

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        public readonly string $id,
        public readonly string $countryCode,
        public readonly PlaceKind $kind,
        public readonly ?string $parentId,
        private TranslatedText $name,
        private bool $retired,
    ) {}

    /**
     * @param  self|null  $parent  the region of a city, the city of a district; none for a region
     */
    public static function create(string $id, string $countryCode, PlaceKind $kind, ?self $parent, TranslatedText $name): self
    {
        $expected = match ($kind) {
            PlaceKind::Region => null,
            PlaceKind::City => PlaceKind::Region,
            PlaceKind::District => PlaceKind::City,
        };

        if ($parent?->kind !== $expected) {
            throw new InvalidPlace('parent', $expected === null ? 'a region has no parent' : "a {$kind->value} sits in a {$expected->value}");
        }

        if ($parent !== null && $parent->countryCode !== $countryCode) {
            throw new InvalidPlace('parent', 'the parent is in another country');
        }

        return new self($id, $countryCode, $kind, $parent?->id, self::checked($name), false);
    }

    public static function reconstitute(string $id, string $countryCode, PlaceKind $kind, ?string $parentId, TranslatedText $name, bool $retired): self
    {
        return new self($id, $countryCode, $kind, $parentId, $name, $retired);
    }

    public function name(): TranslatedText
    {
        return $this->name;
    }

    public function isRetired(): bool
    {
        return $this->retired;
    }

    public function rename(TranslatedText $name): void
    {
        $name = self::checked($name);

        if (! $name->equals($this->name)) {
            $this->name = $name;
            $this->changed[] = 'name';
        }
    }

    public function retire(): void
    {
        $this->mark(true);
    }

    public function restore(): void
    {
        $this->mark(false);
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        $changed = array_values(array_unique($this->changed));
        $this->changed = [];

        return $changed;
    }

    private function mark(bool $retired): void
    {
        if ($this->retired !== $retired) {
            $this->retired = $retired;
            $this->changed[] = 'retired';
        }
    }

    /**
     * Digits saved in Latin (frontend.md §1.8), and each name at most 100 characters.
     */
    private static function checked(TranslatedText $name): TranslatedText
    {
        $name = TranslatedText::of(LatinDigits::of($name->ar), LatinDigits::of($name->en), 'name');

        if (mb_strlen($name->ar) > self::MAX_NAME_LENGTH || mb_strlen($name->en) > self::MAX_NAME_LENGTH) {
            throw new InvalidPlace('name', 'at most '.self::MAX_NAME_LENGTH.' characters in each language');
        }

        return $name;
    }
}
