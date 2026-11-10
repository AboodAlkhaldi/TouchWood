<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query;

use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Domain\Model\Publication;

/**
 * The lists Content's screens show (content.md §4.4), read in SQL: each a page of 50 at most, keyset, the reader's
 * store only. What the forms show is read through the repositories.
 */
interface ScreenReads
{
    public const int PAGE = 50;

    /**
     * W3's rows for one placement, in order, and each placement's count.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function banners(string $storeId, BannerPlacement $placement): array;

    /**
     * W6's rows, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function popups(string $storeId, ?string $after): array;

    /**
     * W2's smart bars, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function smartBars(string $storeId): array;

    /**
     * W7-W9's rows of one tab, last changed first, and each tab's count.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function documents(DocumentKind $kind, string $storeId, Publication $tab, ?string $after, ?string $categoryId): array;

    /**
     * W8's categories in order, each with how many posts it holds.
     *
     * @return list<array<string, mixed>>
     */
    public function blogCategories(string $storeId): array;

    /**
     * Every link staff typed in the store's Content (§1.1 rule 16), as typed: the footer's, the home page's sections'
     * and logos', banners', the bar's, smart bars' and pop-ups'. Which of them name a page is `LinkMatch`'s question.
     *
     * @return list<array{place: string, id: string, link: string}>
     */
    public function links(string $storeId): array;
}
