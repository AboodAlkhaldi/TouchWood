<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Query\ListDailyReports\DailyReportPage;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/** The daily reports and emails as the screens get them (ops.md §4). */
final readonly class DailyReportPages
{
    public function __construct(
        private PlatformApi $platform,
        private AccessApi $access,
        private Translator $words,
        private UrlGenerator $urls,
    ) {}

    public function page(DailyReportPage $page, string $locale): DailyReportPageData
    {
        $names = $this->access->staffDisplayNames(array_values(array_unique(array_map(static fn (DailyReportFile $row): string => $row->madeBy, $page->rows))));
        $stores = $this->stores();

        return new DailyReportPageData(
            array_map(fn (DailyReportFile $row): DailyReportData => $this->report($row, $row->store === null ? null : ($stores[$row->store->value] ?? null), $names[strtolower($row->madeBy)]->name ?? '', $locale), $page->rows),
            $page->next,
        );
    }

    public function one(DailyReportFile $row, string $locale): DailyReportData
    {
        $names = $this->access->staffDisplayNames([$row->madeBy]);
        $store = $row->store === null ? null : $this->platform->store($row->store);

        return $this->report($row, $store, $names[strtolower($row->madeBy)]->name ?? '', $locale);
    }

    /**
     * @param  list<DailyReportEmail>  $emails
     * @return list<DailyReportEmailData>
     */
    public function emails(array $emails, string $allStoresZone, string $locale): array
    {
        $stores = $this->stores();

        return array_map(function (DailyReportEmail $email) use ($stores, $allStoresZone, $locale): DailyReportEmailData {
            $store = $email->store === null ? null : ($stores[$email->store->value] ?? null);

            return new DailyReportEmailData(
                $store?->id,
                $store?->code,
                $this->storeName($store, $locale),
                $email->isOn,
                $email->sendAt,
                $store === null ? $allStoresZone : $store->timezone,
                $email->nextDueAt === null ? null : self::utc($email->nextDueAt),
            );
        }, $emails);
    }

    private function report(DailyReportFile $row, ?StoreDto $store, string $madeBy, string $locale): DailyReportData
    {
        return new DailyReportData(
            $row->id,
            $store?->id,
            $store?->code,
            $this->storeName($store, $locale),
            $row->day,
            $row->pastDay,
            $madeBy,
            self::utc($row->madeAt),
            $row->emailedAt === null ? null : self::utc($row->emailedAt),
            $row->locale,
            $row->bytes,
            $this->urls->route('ops.admin.daily-reports.pdf', ['report' => $row->id]),
        );
    }

    private function storeName(?StoreDto $store, string $locale): string
    {
        return $store === null ? (string) $this->words->get('ops::daily_report.all_stores', [], $locale) : $store->name->in($locale);
    }

    /** @return array<string, StoreDto> */
    private function stores(): array
    {
        $stores = [];
        foreach ($this->platform->allStores() as $store) {
            $stores[$store->id] = $store;
        }

        return $stores;
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.vP');
    }
}
