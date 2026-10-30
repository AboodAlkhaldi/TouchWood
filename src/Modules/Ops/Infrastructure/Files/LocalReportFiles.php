<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Files;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Ops\Application\DailyReport\ReportFiles;
use RuntimeException;

/**
 * The daily reports' PDFs in the app's private files (the `local` disk, storage/app/private): never on
 * the public disk - a report is opened only through its download, which checks who asks.
 */
final readonly class LocalReportFiles implements ReportFiles
{
    public const string DISK = 'local';

    /** The reports' own folder; nothing else is ever swept. */
    private const string ROOT = 'ops/daily-reports';

    public function __construct(private Factory $disks) {}

    public function put(string $path, string $bytes): void
    {
        if (! $this->disk()->put($path, $bytes)) {
            throw new RuntimeException('The daily report could not be written to the private files.');
        }
    }

    public function get(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function delete(array $paths): void
    {
        if ($paths !== []) {
            $this->disk()->delete($paths);
        }
    }

    public function sweepMonthsBefore(DateTimeImmutable $before): int
    {
        $limit = $before->setTimezone(new DateTimeZone('UTC'))->format('Y/m');
        $gone = 0;
        foreach ($this->disk()->directories(self::ROOT) as $year) {
            foreach ($this->disk()->directories($year) as $month) {
                $name = substr($month, strlen(self::ROOT) + 1);
                // A folder is YYYY/MM; anything else here is not the reports' and is left alone.
                if (preg_match('#\A\d{4}/\d{2}\z#', $name) === 1 && $name < $limit) {
                    $gone += count($this->disk()->allFiles($month));
                    $this->disk()->deleteDirectory($month);
                }
            }
            if ($this->disk()->allFiles($year) === [] && $this->disk()->directories($year) === []) {
                $this->disk()->deleteDirectory($year);
            }
        }

        return $gone;
    }

    private function disk(): Filesystem
    {
        return $this->disks->disk(self::DISK);
    }
}
