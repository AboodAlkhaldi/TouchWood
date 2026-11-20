<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A policy's page in the shop (flags-2026-11.md S3.1, S3.2): its title and body's blocks with their images, the number
 * of the text shown and when it was published ("Last updated <date>", F15); its kind. For a text no longer valid in the
 * store - a past version, or a policy switched off there (the owner's decisions of 2026-11-20) - `past`, the dates it was
 * in force there (`inForce`, each from and to, in UTC for the page to show in the store's zone) and the address of the
 * store's current policy of its kind, when it has one.
 */
#[TypeScript]
final class ShopPolicyPageData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, array{src: string|null, width: int|null, height: int|null}>  $images  by the block's `ref`
     * @param  list<array{from: string, until: string}>  $inForce
     */
    public function __construct(
        public string $title,
        public array $blocks,
        public array $images,
        public int $version,
        public ?string $updatedAt,
        public bool $past,
        public ?string $current,
        public ContentAddressData $address,
        public ContentHeadData $head,
        public string $kind,
        public array $inForce,
    ) {}
}
