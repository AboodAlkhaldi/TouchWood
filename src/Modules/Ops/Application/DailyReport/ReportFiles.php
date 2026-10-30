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
}
