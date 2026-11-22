<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The page an emailed link opens (ops.md §4.10, §4.11, §4.15 O13): confirm, leave or unsubscribe. Its words in the
 * address's language, and one button. Nothing about the store but, on Confirm alone, the way back to it: the store
 * is on there, and may be off on the other two.
 */
#[TypeScript]
final class EmailLinkPageData extends Data
{
    public function __construct(
        public string $title,
        public string $text,
        /** The one button's label. */
        public string $action,
        /** The line after the button has done its work. */
        public string $done,
        /** What is said when the button's request goes wrong in a way no refusal names: try again. */
        public string $failed,
        /** Confirm's "Go to the Shop": the store's home in this language; null on the other two pages. */
        public ?string $shopAddress = null,
        public ?string $shopLabel = null,
    ) {}
}
