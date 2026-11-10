<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\TranslateText;

/**
 * One press of Translate, or of Translate All (content.md §1.3): the Arabic of each box asked for, in the
 * kind of item being typed and its store. The item itself is not named - it may not be saved yet - so the
 * permission is the kind's, in that store (§1.3: "the check is by kind, not by item").
 */
final readonly class TranslateText
{
    /**
     * @param  string  $kind  one of ContentPermissions::KINDS ("page", "banner", "theme"...)
     * @param  string|null  $storeId  the store the item is typed in; none for a theme, which spans stores
     * @param  list<array{text: string, rich: bool}>  $boxes  each box's Arabic as typed; `rich` for a text in
     *                                                        the editor's marks (§1.2), translated block by
     *                                                        block
     */
    public function __construct(
        public string $kind,
        public ?string $storeId,
        public array $boxes,
    ) {}
}
