<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Ops\Domain\Repository\DailyReports;
use Modules\Platform\Public\Dto\StoreDto;
use Throwable;

/**
 * Makes a daily report and keeps it (ops.md §1.11): read from the modules now, written as a PDF in the
 * person's language, the file and its row kept - so it opens again exactly as it was, whatever later
 * edits do to that day's figures (§1.10). The button and the email both come here: the email is the
 * button's report at the time chosen.
 */
final readonly class DailyReportMaker
{
    public function __construct(
        private DailyReportReader $reader,
        private DailyReportRenderer $renderer,
        private ReportFiles $files,
        private DailyReports $reports,
    ) {}

    /**
     * @param  StoreDto|null  $store  null: every store that is on, in `$stores`
     * @param  non-empty-list<StoreDto>  $stores
     * @param  string|null  $pastDay  `Y-m-d`, a day before today in `$zone`; null for today until now
     *
     * @throws InvalidOpsInput
     */
    public function make(StaffDto $person, ?StoreDto $store, array $stores, ?string $pastDay, string $zone, ?DateTimeImmutable $now = null): DailyReportFile
    {
        $now ??= CarbonImmutable::now()->toDateTimeImmutable();
        $today = $now->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
        if ($pastDay !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $pastDay, new DateTimeZone($zone));
            if ($parsed === false || $parsed->format('Y-m-d') !== $pastDay) {
                throw new InvalidOpsInput('day', 'a date, YYYY-MM-DD');
            }
            // Before today where the report is read, and in each store it covers: a store east of the base
            // store may already be on the next day.
            foreach ($store === null ? $stores : [$store] as $one) {
                if ($pastDay >= $now->setTimezone(new DateTimeZone($one->timezone))->format('Y-m-d')) {
                    throw new InvalidOpsInput('day', 'a day before today');
                }
            }
            if ($pastDay >= $today) {
                throw new InvalidOpsInput('day', 'a day before today');
            }
        }

        $report = $this->reader->report($store === null ? $stores : [$store], $now, $pastDay, $zone);
        $locale = $person->locale === 'en' ? 'en' : 'ar';
        $pdf = $this->renderer->render($report, $locale);

        $id = strtolower((string) Str::ulid());
        $file = new DailyReportFile(
            id: $id,
            store: $store?->storeId(),
            day: $pastDay ?? ($store === null ? $today : $report->stores[0]->from->format('Y-m-d')),
            pastDay: $pastDay !== null,
            madeBy: $person->id,
            madeAt: $now,
            locale: $locale,
            file: DailyReportFile::fileFor($id, $now),
            bytes: strlen($pdf),
        );
        // The file first: a row never names a file that is not there - and a row that cannot be written
        // leaves no file behind.
        $this->files->put($file->file, $pdf);
        try {
            $this->reports->add($file);
        } catch (Throwable $failed) {
            $this->files->delete([$file->file]);

            throw $failed;
        }

        return $file;
    }
}
