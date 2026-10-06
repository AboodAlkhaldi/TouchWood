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
            'values' => $this->values,
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
        /** @var array<string, string> $values */
        $values = $data['values'] ?? [];
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
