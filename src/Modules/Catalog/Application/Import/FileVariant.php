<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One variant of a product file, as read and checked (the guide, §1.2): names stay as written —
 * the import's page matches them against the catalog.
 */
final readonly class FileVariant
{
    /**
     * @param  array<string, string>  $values  attribute name => value name
     * @param  array<string, array{ar: string, en: string}|string>  $details  attribute name => text in both languages, or a number
     * @param  list<string>  $photos  paths inside the zip
     */
    public function __construct(
        public string $code,
        public array $values,
        public array $details,
        public ?int $weightGrams,
        public ?int $lengthMm,
        public ?int $widthMm,
        public ?int $heightMm,
        public array $photos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            // As pairs, in the file's order: a JSON object kept as jsonb loses its keys' order, and the
            // first variant's order is the order of the product's attributes (amendment 16(b)).
            'values' => array_map(static fn (string $attribute, string $value): array => [$attribute, $value], array_map('strval', array_keys($this->values)), array_values($this->values)),
            'details' => $this->details,
            'weight_g' => $this->weightGrams,
            'length_mm' => $this->lengthMm,
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
            'photos' => $this->photos,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  as toArray() wrote it
     */
    public static function fromArray(array $data): self
    {
        $values = [];

        // Pairs, as written since amendment 16(b); a map, as an import read before kept it.
        foreach (is_array($data['values'] ?? null) ? $data['values'] : [] as $key => $pair) {
            if (is_array($pair) && array_is_list($pair) && count($pair) === 2) {
                $values[(string) $pair[0]] = (string) $pair[1];
            } elseif (is_string($pair)) {
                $values[(string) $key] = $pair;
            }
        }

        /** @var array<string, array{ar: string, en: string}|string> $details */
        $details = $data['details'] ?? [];
        /** @var list<string> $photos */
        $photos = $data['photos'] ?? [];

        return new self(
            (string) $data['code'],
            $values,
            $details,
            self::int($data['weight_g'] ?? null),
            self::int($data['length_mm'] ?? null),
            self::int($data['width_mm'] ?? null),
            self::int($data['height_mm'] ?? null),
            $photos,
        );
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
