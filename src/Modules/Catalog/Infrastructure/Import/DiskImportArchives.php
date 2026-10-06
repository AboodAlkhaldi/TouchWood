<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Import;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use LogicException;
use Modules\Catalog\Application\Import\ImportArchive;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Throwable;
use ZipArchive;

/**
 * Zips kept on the disk `catalog.imports.disk` names (config/catalog.php), under `catalog-imports/`,
 * and read with PHP's zip extension from a temporary local copy — the same on a local disk and on
 * shared storage.
 *
 * A zip's own directory says how large each file is once unpacked; nothing here trusts it further
 * than it can check: two entries for one path are refused, `products.json` is read by its own entry
 * and never past 20 MB, and a photo is copied no further than its declared size — one that does not
 * come out exactly that size is a damaged or altered zip, and refused. Entry names never become
 * paths: every file written has a name of the system's own.
 */
final readonly class DiskImportArchives implements ImportArchives
{
    private const string DIRECTORY = 'catalog-imports';

    private const string MANIFEST = 'products.json';

    /**
     * @param  string  $temporary  the directory unpacked photos and local copies are written in
     */
    public function __construct(
        private Factory $filesystems,
        private string $disk,
        private string $temporary,
    ) {}

    public function open(string $zipPath): ImportArchive
    {
        $size = @filesize($zipPath);

        if ($size === false || $size > self::MAX_BYTES) {
            throw self::refused('a zip of at most 500 MB');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw self::refused('a zip file');
        }

        try {
            $files = [];
            $unpacked = 0;
            $manifest = null;

            foreach (self::entries($zip) as $path => [$index, $entrySize]) {
                $unpacked += $entrySize;

                if ($path === self::MANIFEST) {
                    $manifest = [$index, $entrySize];
                } else {
                    $files[$path] = $entrySize;
                }
            }

            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                throw self::refused('a zip of at most 2 GB once unpacked');
            }

            [$index, $manifestSize] = $manifest ?? [null, PHP_INT_MAX];
            $json = $index === null || $manifestSize > ProductsFile::MAX_BYTES ? false : $zip->getFromIndex($index, ProductsFile::MAX_BYTES + 1);

            if (! is_string($json) || strlen($json) !== $manifestSize) {
                throw self::refused('a products.json of at most 20 MB at the top of the zip');
            }

            return new ImportArchive($json, $files);
        } finally {
            $zip->close();
        }
    }

    public function keep(string $importId, string $zipPath): string
    {
        $archive = self::DIRECTORY.'/'.strtolower($importId).'.zip';
        $stream = fopen($zipPath, 'rb');

        if ($stream === false) {
            throw new LogicException("The zip of import {$importId} could not be read.");
        }

        try {
            if (! $this->disk()->writeStream($archive, $stream)) {
                throw new LogicException("The zip of import {$importId} could not be kept.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $archive;
    }

    public function unpack(string $archive, array $paths): array
    {
        $files = [];
        $local = null;
        $zip = new ZipArchive;
        $opened = false;

        try {
            $local = $this->localCopy($archive);

            if ($zip->open($local, ZipArchive::RDONLY) !== true) {
                throw new LogicException("The kept zip {$archive} cannot be opened.");
            }

            $opened = true;
            $entries = self::entries($zip);

            foreach (array_values(array_unique($paths)) as $path) {
                [$index, $size] = $entries[$path] ?? throw new LogicException("{$path} is not in the kept zip {$archive}.");
                $files[$path] = $this->temporaryFile();
                $this->copy($zip, $index, $size, $files[$path], $path);
            }
        } catch (Throwable $error) {
            $this->release($files);

            throw $error;
        } finally {
            if ($opened) {
                $zip->close();
            }

            if ($local !== null) {
                @unlink($local);
            }
        }

        return $files;
    }

    public function release(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    public function forget(string $archive): void
    {
        $this->disk()->delete($archive);
    }

    /**
     * Each file of the zip by the path the JSON names it with, its entry and its declared size.
     *
     * @return array<string, array{int, int}>
     *
     * @throws ImportRefused when two entries are one path
     */
    private static function entries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if ($entry === false || str_ends_with($entry['name'], '/')) {
                continue;
            }

            $path = ProductsFile::path($entry['name']);

            if (isset($entries[$path])) {
                throw self::refused("{$path} only once in the zip");
            }

            $entries[$path] = [$index, (int) $entry['size']];
        }

        return $entries;
    }

    /**
     * One photo into its file, never past the size the zip declares for it.
     *
     * @throws InvalidCatalogAttribute when it does not come out exactly that size
     */
    private function copy(ZipArchive $zip, int $index, int $size, string $file, string $path): void
    {
        $source = $zip->getStreamIndex($index);
        $target = fopen($file, 'wb');

        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            throw new LogicException("{$path} could not be unpacked.");
        }

        try {
            $copied = stream_copy_to_stream($source, $target, $size + 1);
        } finally {
            fclose($target);
            fclose($source);
        }

        if ($copied !== $size) {
            throw new InvalidCatalogAttribute('photos', "{$path}: not the size the zip gives for it — a damaged or altered zip");
        }
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk($this->disk);
    }

    /** A new empty file, named by the system — Windows keeps only three letters of a prefix. */
    private function temporaryFile(): string
    {
        $file = tempnam($this->temporary, 'twi');

        if ($file === false) {
            throw new LogicException('A temporary file could not be made.');
        }

        return $file;
    }

    private function localCopy(string $archive): string
    {
        $copy = $this->temporaryFile();

        try {
            $source = $this->disk()->readStream($archive);
            $target = fopen($copy, 'wb');

            if ($source === null || $target === false) {
                throw new LogicException("The kept zip {$archive} cannot be read.");
            }

            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);

                if (is_resource($source)) {
                    fclose($source);
                }
            }
        } catch (Throwable $error) {
            @unlink($copy);

            throw $error;
        }

        return $copy;
    }

    private static function refused(string $problem): ImportRefused
    {
        return new ImportRefused([['at' => 'file', 'problem' => $problem]]);
    }
}
