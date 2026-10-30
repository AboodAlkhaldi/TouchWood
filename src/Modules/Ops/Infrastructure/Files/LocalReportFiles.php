<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Files;

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

    private function disk(): Filesystem
    {
        return $this->disks->disk(self::DISK);
    }
}
