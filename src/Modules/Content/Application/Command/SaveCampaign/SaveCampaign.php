<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveCampaign;

/**
 * A campaign's shared fields written (content.md §1.7 #1, #8): its name per language, its start and end as typed
 * (`YYYY-MM-DD HH:MM`, each store reading them on its own clock), its stores (one run each) and its theme link. No id:
 * a new draft. `version` is the one the form read (§1.1 rule 12); a new one sends 0. `translated` and `read` are
 * Translate's mark on the English name (§1.3 #4).
 */
final readonly class SaveCampaign
{
    /**
     * @param  list<string>  $storeIds
     */
    public function __construct(
        public ?string $campaignId,
        public int $version,
        public ?string $nameAr,
        public ?string $nameEn,
        public ?string $startsAt,
        public ?string $endsAt,
        public array $storeIds,
        public ?string $themeId = null,
        /** An English box still carries Translate's mark (content.md §1.3, P14). */
        public bool $translated = false,
        /** "I read the translated English." ticked: the mark goes. */
        public bool $read = false,
    ) {}
}
