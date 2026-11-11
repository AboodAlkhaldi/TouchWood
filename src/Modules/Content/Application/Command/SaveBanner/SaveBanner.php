<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveBanner;

/**
 * A banner's page (content.md §1.6, W3): **Save Draft** for a new one (`bannerId` null, in `storeId`) or **Save** for
 * one that exists. `images` is `{DESKTOP|PHONE}:{any|ar|en}` => media id; dates are typed in the store's zone.
 * The placement is chosen once, with the banner.
 */
final readonly class SaveBanner
{
    /**
     * @param  array<string, string|null>  $images
     */
    public function __construct(
        public ?string $bannerId,
        public ?string $storeId,
        public int $version,
        public string $placement,
        public ?string $categoryId,
        public string $name,
        public ?string $link,
        public bool $hasWords,
        public ?string $altAr,
        public ?string $altEn,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $images,
        public bool $translated = false,
        public bool $read = false,
    ) {}
}
