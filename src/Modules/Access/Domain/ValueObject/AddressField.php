<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * One field of a store's address format (spec §1.9): its key, its name in both languages, whether
 * an address must carry it, how long its value may be, and where it sits on the form. Staff change
 * these as data, so every value is checked here before it is stored.
 */
final readonly class AddressField
{
    private const string KEY = '/\A[a-z][a-z0-9_]{1,39}\z/';

    private const int LABEL_MAX = 60;

    /** Public for the editor, which says the rule before anybody types (see StoreAddressFormat). */
    public const int LENGTH_MAX = 1000;

    private function __construct(
        public string $key,
        public string $labelAr,
        public string $labelEn,
        public bool $required,
        public int $maxLength,
        public int $order,
    ) {}

    /**
     * @throws InvalidAccessAttribute
     */
    public static function of(string $key, string $labelAr, string $labelEn, bool $required, int $maxLength, int $order): self
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidAccessAttribute('field.key', 'lower-case letters, digits and underscores, 2 to 40 characters');
        }

        foreach (['field.label_ar' => $labelAr, 'field.label_en' => $labelEn] as $attribute => $label) {
            if (trim($label) === '' || mb_strlen(trim($label)) > self::LABEL_MAX) {
                throw new InvalidAccessAttribute($attribute, 'between 1 and '.self::LABEL_MAX.' characters');
            }
        }

        if ($maxLength < 1 || $maxLength > self::LENGTH_MAX) {
            throw new InvalidAccessAttribute('field.max_length', 'between 1 and '.self::LENGTH_MAX);
        }

        if ($order < 0 || $order > 999) {
            throw new InvalidAccessAttribute('field.order', 'between 0 and 999');
        }

        return new self($key, trim($labelAr), trim($labelEn), $required, $maxLength, $order);
    }

    /**
     * @param  array<string, mixed>  $row  as it is stored in the format's jsonb
     *
     * @throws InvalidAccessAttribute
     */
    public static function fromArray(array $row): self
    {
        return self::of(
            is_string($row['key'] ?? null) ? $row['key'] : '',
            is_string($row['label_ar'] ?? null) ? $row['label_ar'] : '',
            is_string($row['label_en'] ?? null) ? $row['label_en'] : '',
            ($row['required'] ?? false) === true,
            is_int($row['max_length'] ?? null) ? $row['max_length'] : 0,
            is_int($row['order'] ?? null) ? $row['order'] : 0,
        );
    }

    /**
     * @return array{key: string, label_ar: string, label_en: string, required: bool, max_length: int, order: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label_ar' => $this->labelAr,
            'label_en' => $this->labelEn,
            'required' => $this->required,
            'max_length' => $this->maxLength,
            'order' => $this->order,
        ];
    }
}
