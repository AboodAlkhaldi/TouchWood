<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sync\Application\Report\ReadViews;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\ReportLines;
use Modules\Sync\Application\Report\SizeNames;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\Report\SyncWords;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\ReadNotFound;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/**
 * **One read's page** (owner, 2026-10-17, sync.md §9.3 #19; §4.1 Y2), for staff with View the Sync Report
 * in its store: the header - kind, state, who asked, when, how long, the counts, a failed read's reason -
 * and one section at a time, each paged 100 on its own (Q32): prices changed and stock changed, from and
 * to, written; what it reported as it was that day (owner, 2026-10-18), named as the report names it; the
 * codes a notice's or a code's read asked for. The read is looked up first, to know its store - as Pricing
 * finds a sale before asking about its store - so one pruned after 90 days, one of a store the person
 * holds nothing of Sync's in, or never one, is not found alike (§9.3 #29).
 */
final readonly class ViewSyncReadHandler
{
    public const int PER_PAGE = 100;

    public function __construct(
        private ReportAccess $access,
        private PlatformApi $platform,
        private SyncLog $log,
        private SizeNames $names,
        private ReportLines $reports,
        private ReadViews $reads,
        private SyncWords $words,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput|ReadNotFound
     */
    public function handle(ViewSyncRead $query): ReadDetailView
    {
        $read = $this->log->read($query->readId) ?? throw new ReadNotFound;
        $store = $this->platform->store($this->access->authorize(SyncPermissions::REPORT_VIEW, $read['storeId'], new ReadNotFound)) ?? throw new ReadNotFound;
        $section = strtoupper(trim($query->section));

        if (! in_array($section, ViewSyncRead::SECTIONS, true)) {
            throw new InvalidSyncInput('section');
        }

        $page = max(1, $query->page);
        $codes = $read['codes'] ?? [];
        $sections = [...$this->log->sections($read['id']), 'codes' => count($codes)];
        $lines = $reported = $shownCodes = [];

        if ($section === ViewSyncRead::PRICES || $section === ViewSyncRead::STOCK) {
            $lines = $this->lines($read['id'], $section, $page, $store);
        } elseif ($section === ViewSyncRead::REPORTED) {
            $reported = $this->reports->describe($this->log->reported($read['id'], $page, self::PER_PAGE));
        } else {
            $shownCodes = array_slice($codes, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        }

        $inSection = match ($section) {
            ViewSyncRead::PRICES => $sections['prices'],
            ViewSyncRead::STOCK => $sections['stock'],
            ViewSyncRead::REPORTED => $sections['reported'],
            default => $sections['codes'],
        };

        return new ReadDetailView(
            $this->reads->of([$read])[0],
            $store->id,
            $store->code,
            $store->timezone,
            $sections,
            $section,
            $page,
            self::PER_PAGE,
            $inSection > $page * self::PER_PAGE,
            $lines,
            $reported,
            $shownCodes,
        );
    }

    /**
     * @return list<ReadLineView>
     */
    private function lines(string $readId, string $section, int $page, StoreDto $store): array
    {
        $rows = $this->log->lines($readId, $section === ViewSyncRead::PRICES ? 'PRICE' : 'STOCK', $page, self::PER_PAGE);
        $names = $this->names->of(array_column($rows, 'variantId'));
        $locale = $this->words->locale();
        $write = $section === ViewSyncRead::PRICES
            ? static function (?string $amount) use ($store, $locale): ?string {
                if ($amount === null) {
                    return null;
                }

                try {
                    return MoneyData::of(Money::fromDecimal($amount, $store->currencyCode, $store->currencyExponent), $store, $locale)->text;
                } catch (MoneyException) {
                    // Never one Pricing wrote: shown as it was kept rather than not at all.
                    return $amount;
                }
            }
        : static fn (?string $number): ?string => $number;

        return array_map(static function (array $row) use ($names, $write): ReadLineView {
            $size = $names[strtolower($row['variantId'])] ?? null;

            return new ReadLineView(
                $row['variantId'],
                $row['code'] ?? $size['code'] ?? null,
                $size !== null,
                $size['productId'] ?? null,
                $size['nameAr'] ?? null,
                $size['nameEn'] ?? null,
                $size['values'] ?? [],
                $write($row['before']),
                (string) $write($row['after']),
            );
        }, $rows);
    }
}
