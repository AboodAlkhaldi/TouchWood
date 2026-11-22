<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A button of the home's greeting row (dashboard.md §2 A, §2.1: Today's Report): the page posts `fields` to
 * `href`, asking for JSON, and opens the `downloadUrl` the answer names. The action behind it asks its own
 * permission again: what the home offers is not protection.
 */
#[TypeScript]
final readonly class HomeAction
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        /** Already in the reader's language. */
        public string $label,
        public string $href,
        public array $fields,
        /** Already in words: what the button says while it waits. */
        public string $busy,
        /** Already in words: what the page says when the action fails. */
        public string $failed,
    ) {}
}
