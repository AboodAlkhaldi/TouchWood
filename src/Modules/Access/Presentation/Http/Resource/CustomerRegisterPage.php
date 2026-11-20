<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\ShopPolicyLinkData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * F3 - registering (frontend.md §3.6). The password rule is shown in words, and its number is the
 * setting's, so a shop that raises it says so on the form without a deploy. The terms' sentence names
 * the store's published Terms and Conditions and Privacy Policy, each opening its page in a new tab
 * (flags-2026-11.md S3.4); one the store has not published is its plain name. Their ids and versions go
 * back with the tick, refused unless they are still the store's (owner, 2026-11-20).
 */
#[TypeScript]
final class CustomerRegisterPage extends Data
{
    public function __construct(
        public int $minimumLength,
        public ?ShopPolicyLinkData $terms = null,
        public ?ShopPolicyLinkData $privacy = null,
    ) {}
}
