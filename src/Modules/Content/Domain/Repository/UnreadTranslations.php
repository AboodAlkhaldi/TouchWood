<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use DateTimeImmutable;

/**
 * Items whose English Translate filled and nobody has said they read (content.md §1.3, P14): one mark an item, kept
 * across sessions until the tick clears it (`content.unread_translations`). A page, post or project is marked under
 * its `DocumentKind`'s value.
 */
interface UnreadTranslations
{
    public const string BANNER = 'BANNER';

    public const string POPUP = 'POPUP';

    public const string HOME_SECTION = 'HOME_SECTION';

    /** The announcement bar: its item is its store. */
    public const string BAR = 'BAR';

    public function has(string $kind, string $itemId): bool;

    /**
     * Which of the items carry the mark.
     *
     * @param  list<string>  $itemIds
     * @return list<string> those marked, lower-cased
     */
    public function marked(string $kind, array $itemIds): array;

    public function mark(string $kind, string $itemId, string $storeId, ?string $by, DateTimeImmutable $at): void;

    public function clear(string $kind, string $itemId): void;
}
