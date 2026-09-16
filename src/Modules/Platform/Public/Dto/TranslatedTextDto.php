<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\LaravelData\Data;

final class TranslatedTextDto extends Data
{
    public function __construct(
        public readonly string $ar,
        public readonly string $en,
    ) {}

    /**
     * The value for a locale. Arabic is the default language (handoff §5.2).
     */
    public function in(string $locale): string
    {
        return $locale === 'en' ? $this->en : $this->ar;
    }
}
