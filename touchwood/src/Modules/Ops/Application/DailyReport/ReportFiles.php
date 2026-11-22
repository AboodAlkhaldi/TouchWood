<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

/** Where the daily reports' PDFs are kept (ops.md §1.11: every file made is kept): the app's private files. */
interface ReportFiles
{
    public function put(string $path, string $bytes): void;

    /** The file's bytes; null when it is gone. */
    public function get(string $path): ?string;

    /** @param  list<string>  $paths */
    public function delete(array $paths): void;

    /**
     * Removes the daily reports' month folders wholly before the moment's month (UTC), whatever is left in
     * them - a file whose row is gone but whose delete failed never stays for good.
     *
     * @return int how many files went
     */
    public function sweepMonthsBefore(\DateTimeImmutable $before): int;
}
