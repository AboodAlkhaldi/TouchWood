<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveFooter;

/**
 * The Footer tab (content.md §1.5, W1): a line about the store, phone, email, an address line, the copyright line,
 * and up to 3 columns of up to 8 links, each `{titleAr, titleEn, links: [{textAr, textEn, link}]}`.
 */
final readonly class SaveFooter
{
    /**
     * @param  list<array{titleAr: string|null, titleEn: string|null, links: list<array{textAr: string|null, textEn: string|null, link: string|null}>}>  $columns
     */
    public function __construct(
        public ?string $storeId,
        public int $version,
        public ?string $aboutAr,
        public ?string $aboutEn,
        public ?string $phone,
        public ?string $email,
        public ?string $addressAr,
        public ?string $addressEn,
        public ?string $copyrightAr,
        public ?string $copyrightEn,
        public array $columns,
    ) {}
}
