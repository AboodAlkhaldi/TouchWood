<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use LogicException;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Unauthorized;

/**
 * A report as a request asks for it (ops.md §1.10, §3, §7): who may read it and which stores it covers are
 * settled here, before a row is read; the rows are read as the sheet is written.
 *
 * In the reader's language (their account's), with a title, the store - or "All stores" - and its days
 * (or the moment, for Stock and Favourites, read in the store's time zone, or the base store's for every
 * store's). The file is named after the report, the store's code and the days, in Latin letters.
 */
final readonly class ReportBuilder
{
    /** What a request names for every store's report - a super admin's alone. */
    public const string ALL = 'all';

    public function __construct(
        private ReportReaders $readers,
        private Translator $translator,
        private RevenueSheets $revenue,
        private ListSheets $lists,
    ) {}

    /**
     * `$store` is a store's id, or `all`.
     *
     * @throws Unauthorized|InvalidOpsInput
     */
    public function build(string $kind, string $store, ?string $from, ?string $to, ?string $by): Report
    {
        $kind = ReportKind::tryFrom($kind) ?? throw new InvalidOpsInput('report', 'not a report');
        $person = $this->readers->person();
        $all = $store === self::ALL;
        $stores = $this->readers->stores($kind, $all ? null : $store, $person);
        $words = new ReportWords($this->translator, $person->locale === 'en' ? 'en' : 'ar');
        $where = $all ? $words->get('all_stores') : $words->store($stores[0]);
        $code = $all ? 'all' : $stores[0]->code;

        if (! $kind->hasRange()) {
            $now = CarbonImmutable::now($all ? $this->readers->baseZone() : $stores[0]->timezone);
            $sheet = $kind === ReportKind::Stock ? $this->lists->stock($stores, $words) : $this->lists->favourites($stores, $words);

            return new Report($kind, $words->locale, $words->get('titles.'.$kind->value), $words->get('as_at', ['store' => $where, 'time' => $now->format('Y-m-d H:i')]), [$sheet], "{$kind->value}-{$code}-{$now->format('Y-m-d')}.xlsx");
        }

        $period = ReportPeriod::of($from, $to);
        $sheets = match ($kind) {
            ReportKind::Revenue => $this->revenue->sheets($stores, $period, self::byMonth($by), $words),
            ReportKind::ProductSales => [$this->lists->productSales($stores, $period, $words)],
            ReportKind::Searches => $period->withinTwelveMonths() ? [$this->lists->searches($stores, $period, $words)] : throw new InvalidOpsInput('to', 'more than twelve months after the first day'),
            ReportKind::Stock, ReportKind::Favourites => throw new LogicException('A report of now has no days.'),
        };

        return new Report(
            $kind,
            $words->locale,
            $words->get('titles.'.$kind->value),
            $words->get('period', ['store' => $where, 'from' => $period->fromDay, 'to' => $period->toDay]),
            $sheets,
            "{$kind->value}-{$code}-{$period->fromDay}-{$period->toDay}.xlsx",
        );
    }

    /** Revenue per day, or per month (§1.10); per day when the request says neither. */
    private static function byMonth(?string $by): bool
    {
        return match ($by) {
            null, 'day' => false,
            'month' => true,
            default => throw new InvalidOpsInput('by', 'neither day nor month'),
        };
    }
}
