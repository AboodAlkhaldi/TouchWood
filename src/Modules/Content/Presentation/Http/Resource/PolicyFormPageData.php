<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * New Policy and a policy's own page (flags-2026-11.md S3.7; the owner's answers of 2026-11-20): the stores the person
 * may tick (a checkbox each: they hold the job there), the names of the stores the policy also serves that are not
 * theirs to change (shown read-only), the policy (none when new), every file it shows as its boxes show them, the
 * kinds in their order, the shop's root its address is drawn under (`{shopRoot}/{store}/{locale}/policies/{slug}`),
 * and whether Translate works here.
 */
#[TypeScript]
final class PolicyFormPageData extends Data
{
    /**
     * @param  list<ContentStoreData>  $stores
     * @param  list<string>  $otherStores  names in the page's language
     * @param  array<string, ContentImageData>  $images  by media id
     * @param  list<string>  $kinds
     */
    public function __construct(
        public array $stores,
        public array $otherStores,
        public ?PolicyData $policy,
        public array $images,
        public array $kinds,
        public string $shopRoot,
        public bool $translationReady,
    ) {}
}
