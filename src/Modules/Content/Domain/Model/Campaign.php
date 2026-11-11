<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A campaign (content.md §1.7): a named period with prices, separate from a theme. Its name per language (shown to
 * staff and in S8's alert), its start and end **as typed** (`Y-m-d H:i`, each store reading them on its own clock,
 * Q1 (a)), an optional link to a theme, and a version for two people editing (§1.1 rule 12). Its stores are its runs.
 */
final readonly class Campaign
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public string $startsLocal,
        public string $endsLocal,
        public ?string $themeId,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, bool|int|string|null> its short fields, as the audit records them
     */
    public function fields(): array
    {
        return [
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'starts_at_local' => $this->startsLocal,
            'ends_at_local' => $this->endsLocal,
            'theme_id' => $this->themeId,
        ];
    }
}
