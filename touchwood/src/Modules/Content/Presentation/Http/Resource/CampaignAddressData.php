<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A campaign page's full web address in one language (W4b; H7), on the site's canonical host. */
#[TypeScript]
final class CampaignAddressData extends Data
{
    public function __construct(
        public string $locale,
        public string $url,
    ) {}
}
