<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use DateTimeImmutable;
use Modules\Platform\Public\Enums\PolicyKind;

/**
 * A store's published policy as a shopper reads it now (flags-2026-11.md S3): which one it is, the version of its text
 * shown (F14 - what a customer accepts is this number), its title and address in the language asked, and when its text
 * last changed ("Last updated", F15). Its page is `/{store}/{locale}/policies/{slug}`; a past version's text is the
 * same address with `?version={number}`.
 */
final readonly class ShopPolicyDto
{
    public function __construct(
        public string $policyId,
        public PolicyKind $kind,
        public int $version,
        public string $title,
        public string $slug,
        public DateTimeImmutable $updatedAt,
    ) {}
}
