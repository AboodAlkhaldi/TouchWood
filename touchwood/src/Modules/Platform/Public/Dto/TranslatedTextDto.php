<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

final readonly class TranslatedTextDto
{
    public function __construct(
        public string $ar,
        public string $en,
    ) {}

    /**
     * The value for a locale. Arabic is the default language (handoff §5.2).
     */
    public function in(string $locale): string
    {
        return $locale === 'en' ? $this->en : $this->ar;
    }
}
