<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Files;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Content\Application\Sitemap\SitemapFiles;

/**
 * `SitemapFiles` on Laravel's private `local` disk (content.md §5: "File in private storage, served by the routes"):
 * never under `public/`, so a file is only ever reached through Content's routes, which leave off stores out.
 */
final readonly class LocalSitemapFiles implements SitemapFiles
{
    public const string DISK = 'local';

    public function __construct(
        private Factory $storage,
    ) {}

    public function put(string $key, string $contents): void
    {
        $this->disk()->put($key, $contents);
    }

    public function get(string $key): ?string
    {
        $disk = $this->disk();

        return $disk->exists($key) ? $disk->get($key) : null;
    }

    public function delete(array $keys): void
    {
        if ($keys !== []) {
            $this->disk()->delete($keys);
        }
    }

    private function disk(): Filesystem
    {
        return $this->storage->disk(self::DISK);
    }
}
