<?php

declare(strict_types=1);

namespace Modules\Content\Application\Policies;

/**
 * What a policy's form sends (flags-2026-11.md S3.7): a new one (`id` null) or one that exists, at the `version` the
 * form read; its kind (one of `PolicyKind`, fixed once made); the stores ticked, at least one, each one the person may
 * work in (a policy's other stores are not on their form); its words per language and its share image per language
 * (`shareAr` / `shareEn`, media ids) as a page's (content.md §1.11, §1.14). Bodies are the editor's typed marks
 * (§1.2); an address left empty is made from its title; `imagesAr` / `imagesEn` give each `![...](image:N)` of the
 * body its file, ref => media id. `translated`: an English box still carries Translate's mark; `read`: "I read the
 * translated English." was ticked (§1.3, P14).
 */
final readonly class PolicyInput
{
    /**
     * @param  list<string>  $storeIds
     * @param  array<int, string>  $imagesAr
     * @param  array<int, string>  $imagesEn
     */
    public function __construct(
        public ?string $id,
        public ?string $kind,
        public array $storeIds,
        public int $version,
        public ?string $titleAr,
        public ?string $titleEn,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?string $bodyAr = null,
        public ?string $bodyEn = null,
        public array $imagesAr = [],
        public array $imagesEn = [],
        public ?string $seoTitleAr = null,
        public ?string $seoTitleEn = null,
        public ?string $seoDescriptionAr = null,
        public ?string $seoDescriptionEn = null,
        public ?string $shareAr = null,
        public ?string $shareEn = null,
        public bool $translated = false,
        public bool $read = false,
    ) {}
}
