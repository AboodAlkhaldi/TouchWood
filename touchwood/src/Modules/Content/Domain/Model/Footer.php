<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A store's footer (content.md §1.5): a line about the store, phone, email, an address line, up to 3 columns of up
 * to 8 links, the copyright line. `columns` is `[{title: {ar, en}, links: [{text: {ar, en}, link}]}]`.
 */
final readonly class Footer
{
    /**
     * @param  list<array{title: array{ar: string, en: string}, links: list<array{text: array{ar: string, en: string}, link: string}>}>  $columns
     */
    public function __construct(
        public string $storeId,
        public ?string $aboutAr,
        public ?string $aboutEn,
        public ?string $phone,
        public ?string $email,
        public ?string $addressAr,
        public ?string $addressEn,
        public ?string $copyrightAr,
        public ?string $copyrightEn,
        public array $columns,
        public int $version,
        public ?string $updatedBy,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public static function none(string $storeId): self
    {
        return new self($storeId, null, null, null, null, null, null, null, null, [], 0, null, null);
    }

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'about_ar' => $this->aboutAr,
            'about_en' => $this->aboutEn,
            'phone' => $this->phone,
            'email' => $this->email,
            'address_ar' => $this->addressAr,
            'address_en' => $this->addressEn,
            'copyright_ar' => $this->copyrightAr,
            'copyright_en' => $this->copyrightEn,
            'columns' => json_encode($this->columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }
}
