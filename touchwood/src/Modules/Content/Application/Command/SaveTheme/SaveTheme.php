<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveTheme;

/**
 * A theme written (content.md §1.8, W5b): its name per language, its start and end as typed (`YYYY-MM-DD HH:MM`, each
 * store reading them on its own clock), its stores and its 11 parts as typed - each `{part, textAr?, textEn?, link?,
 * extras?, images?: {"SLOT:locale": mediaId}}`, a part left out emptied. No id: a new draft. `version` as the form read
 * it (0 for a new one).
 */
final readonly class SaveTheme
{
    /**
     * @param  list<string>  $storeIds
     * @param  list<array<string, mixed>>  $parts
     */
    public function __construct(
        public ?string $themeId,
        public int $version,
        public ?string $nameAr,
        public ?string $nameEn,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $storeIds,
        public array $parts = [],
        /** An English box still carries Translate's mark (content.md §1.3, P14). */
        public bool $translated = false,
        /** "I read the translated English." ticked: the mark goes. */
        public bool $read = false,
    ) {}
}
