<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use LogicException;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\ReportRangeRefused;
use Shared\Application\Unauthorized;

/**
 * A report as a request asks for it (ops.md §1.10, §7), once its handler has checked the permissions (§3):
 * which stores it covers - every store's a super admin's alone - and its days are settled here, before a
 * row is read; the rows are read as the sheet is written.
 *
 * In the reader's language (their account's), with a title, the store - or "All stores" - and its days
 * (or the moment, for Stock and Favourites, read in the store's time zone, or the base store's for every
 * store's). The file is named after the report, the store's code and the days, in Latin letters.
 */
final readonly class ReportBuilder
{
    public function __construct(
        private ReportReaders $readers,
        private Translator $translator,
        private RevenueSheets $revenue,
        private ListSheets $lists,
    ) {}

    /**
     * The report of an ask whose permissions its handler has checked.
     *
     * @throws Unauthorized|InvalidOpsInput|ReportRangeRefused
     */
    public function build(ReportAsk $ask, ?string $from, ?string $to, ?string $by): Report
    {
        $kind = $ask->kind;
        $person = $ask->person;
        $stores = $this->readers->stores($ask);
        $all = $ask->store === null;
        $words = new ReportWords($this->translator, $person->locale === 'en' ? 'en' : 'ar');
        $where = $all ? $words->get('all_stores') : $words->store($stores[0]);
        $code = $all ? 'all' : $stores[0]->code;

        // One moment for the whole request: every page of every sheet reads up to it (a range that includes
        // today ends there), and a report of now is as at it - shown in the store's time zone, or the base
        // store's for every store's (ops.md §1.11).
        $now = CarbonImmutable::now($all ? $this->readers->baseZone() : $stores[0]->timezone)->toDateTimeImmutable();
        $time = $now->format('Y-m-d H:i');

        if (! $kind->hasRange()) {
            $sheet = $kind === ReportKind::Stock ? $this->lists->stock($stores, $words) : $this->lists->favourites($stores, $words);

            return new Report($kind, $words->locale, $words->get('titles.'.$kind->value), $words->get('as_at', ['store' => $where, 'time' => $time]), [$sheet], "{$kind->value}-{$code}-{$now->format('Y-m-d')}.xlsx");
        }

        $period = ReportPeriod::of($from, $to, $now, $kind === ReportKind::Searches);
        $sheets = match ($kind) {
            ReportKind::Revenue => $this->revenue->sheets($stores, $period, self::byMonth($by), $words),
            ReportKind::ProductSales => [$this->lists->productSales($stores, $period, $words)],
            ReportKind::Searches => [$this->lists->searches($stores, $period, $words)],
            ReportKind::Stock, ReportKind::Favourites => throw new LogicException('A report of now has no days.'),
        };
        if ($period->cutAtNow(...$stores)) {
            $until = $words->get('notes.until', ['time' => $time]);
            $sheets = array_map(static fn (ReportSheet $sheet): ReportSheet => new ReportSheet($sheet->name, $sheet->columns, [$until, ...$sheet->notes], $sheet->rows), $sheets);
        }

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
