<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * A banner over the admin panel, in the reader's language: a short label, one sentence, an optional link.
 * `$everywhere`: on every page; otherwise on Home only.
 */
final readonly class AdminBannerDto
{
    public function __construct(
        public string $label,
        public string $text,
        public bool $everywhere,
        public ?string $linkText = null,
        public ?string $href = null,
    ) {}
}
