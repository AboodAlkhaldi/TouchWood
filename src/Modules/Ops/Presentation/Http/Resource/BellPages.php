<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use DateTimeZone;
use Modules\Ops\Application\Inbox\InboxPage;
use Modules\Ops\Application\Inbox\InboxRow;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/** A bell's page as the screens get it (ops.md §4): each entry named with its store's code and name. */
final readonly class BellPages
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function page(InboxPage $page, string $locale): InboxPageData
    {
        $stores = [];

        foreach ($this->platform->allStores() as $store) {
            $stores[$store->id] = $store;
        }

        return new InboxPageData(
            array_map(static fn (InboxRow $row): InboxEntryData => self::entry($row, $stores[$row->storeId] ?? null, $locale), $page->rows),
            $page->next,
            $page->unread,
        );
    }

    private static function entry(InboxRow $row, ?StoreDto $store, string $locale): InboxEntryData
    {
        return new InboxEntryData(
            $row->id,
            $row->kind,
            $store === null ? '' : $store->code,
            $store === null ? '' : $store->name->in($locale),
            $row->title,
            $row->line,
            $row->link,
            $row->read,
            $row->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP'),
        );
    }
}
