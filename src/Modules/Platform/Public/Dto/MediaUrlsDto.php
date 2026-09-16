<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class MediaUrlsDto extends Data
{
    /**
     * @param  string|null  $original  an expiring link to a private file; null for public images, which are shown only through their variants
     * @param  array<string, array<string, string>>  $variants  size slug => format extension => CDN URL; empty until variants are ready, and always for private files
     * @param  CarbonImmutable|null  $expiresAt  when a private link stops working; null for public media
     */
    public function __construct(
        public readonly ?string $original,
        public readonly array $variants,
        public readonly ?CarbonImmutable $expiresAt,
    ) {}
}
