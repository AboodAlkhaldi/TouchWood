<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Model;

use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Shared\Domain\Text\LatinDigits;

/**
 * One of a store's boxes (shipping.md §1.7): its name, its inner sizes in millimetres, the most weight
 * it takes in grams, on or off, and its place in the list. Only a suggestion is ever made from it: it
 * never changes a price and never blocks anything.
 */
final class Box
{
    public const int MOST = 1000000;

    private function __construct(
        public readonly string $id,
        public readonly string $storeId,
        private string $name,
        private int $lengthMm,
        private int $widthMm,
        private int $heightMm,
        private int $maxWeightG,
        private bool $active,
        public readonly int $position,
    ) {}

    /**
     * @throws InvalidShippingInput
     */
    public static function create(string $id, string $storeId, string $name, int $lengthMm, int $widthMm, int $heightMm, int $maxWeightG, bool $active, int $position): self
    {
        return new self($id, $storeId, self::checkedName($name), self::size('length_mm', $lengthMm), self::size('width_mm', $widthMm), self::size('height_mm', $heightMm), self::size('max_weight_g', $maxWeightG), $active, $position);
    }

    public static function reconstitute(string $id, string $storeId, string $name, int $lengthMm, int $widthMm, int $heightMm, int $maxWeightG, bool $active, int $position): self
    {
        return new self($id, $storeId, $name, $lengthMm, $widthMm, $heightMm, $maxWeightG, $active, $position);
    }

    /**
     * @throws InvalidShippingInput
     */
    public function change(string $name, int $lengthMm, int $widthMm, int $heightMm, int $maxWeightG): void
    {
        $this->name = self::checkedName($name);
        $this->lengthMm = self::size('length_mm', $lengthMm);
        $this->widthMm = self::size('width_mm', $widthMm);
        $this->heightMm = self::size('height_mm', $heightMm);
        $this->maxWeightG = self::size('max_weight_g', $maxWeightG);
    }

    public function switch(bool $on): void
    {
        $this->active = $on;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array{int, int, int} length, width, height
     */
    public function sizes(): array
    {
        return [$this->lengthMm, $this->widthMm, $this->heightMm];
    }

    public function maxWeightG(): int
    {
        return $this->maxWeightG;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'length_mm' => (string) $this->lengthMm,
            'width_mm' => (string) $this->widthMm,
            'height_mm' => (string) $this->heightMm,
            'max_weight_g' => (string) $this->maxWeightG,
            'active' => $this->active ? 'on' : 'off',
        ];
    }

    private static function checkedName(string $value): string
    {
        $name = LatinDigits::of(trim($value));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidShippingInput('name', '1 to 100 characters');
        }

        return $name;
    }

    private static function size(string $field, int $value): int
    {
        if ($value < 1 || $value > self::MOST) {
            throw new InvalidShippingInput($field, '1 to '.self::MOST);
        }

        return $value;
    }
}
