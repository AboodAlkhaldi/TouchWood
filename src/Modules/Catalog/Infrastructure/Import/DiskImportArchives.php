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
use ZipArchive;

/**
 * Zips kept on the disk `catalog.imports.disk` names (config/catalog.php), under `catalog-imports/`,
 * and read with PHP's zip extension from a temporary local copy — the same on a local disk and on
 * shared storage.
 */
final readonly class DiskImportArchives implements ImportArchives
{
    private const string DIRECTORY = 'catalog-imports';

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
            throw new ImportRefused([['at' => 'file', 'problem' => 'a zip of at most 500 MB']]);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ImportRefused([['at' => 'file', 'problem' => 'a zip file']]);
        }

        try {
            $files = [];
            $unpacked = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false || str_ends_with($entry['name'], '/')) {
                    continue;
                }

                $files[ProductsFile::path($entry['name'])] = $entry['size'];
                $unpacked += $entry['size'];
            }

            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                throw new ImportRefused([['at' => 'file', 'problem' => 'a zip of at most 2 GB once unpacked']]);
            }

            $json = ($files['products.json'] ?? PHP_INT_MAX) <= ProductsFile::MAX_BYTES ? $zip->getFromName('products.json') : false;

            if (! is_string($json)) {
                throw new ImportRefused([['at' => 'file', 'problem' => 'a products.json of at most 20 MB at the top of the zip']]);
            }

            unset($files['products.json']);

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
        $local = $this->localCopy($archive);
        $zip = new ZipArchive;
        $files = [];

        try {
            if ($zip->open($local, ZipArchive::RDONLY) !== true) {
                throw new LogicException("The kept zip {$archive} cannot be opened.");
            }

            // Each entry by the path the file named, as open() read the zip's names.
            $names = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (is_string($name)) {
                    $names[ProductsFile::path($name)] = $name;
                }
            }

            foreach (array_values(array_unique($paths)) as $path) {
                $stream = isset($names[$path]) ? $zip->getStream($names[$path]) : false;

                if ($stream === false) {
                    throw new LogicException("{$path} is not in the kept zip {$archive}.");
                }

                $file = $this->temporaryFile();
                $target = fopen($file, 'wb');

                if ($target === false) {
                    @unlink($file);

                    throw new LogicException('A temporary file could not be written.');
                }

                stream_copy_to_stream($stream, $target);
                fclose($target);
                fclose($stream);
                $files[$path] = $file;
            }
        } catch (LogicException $error) {
            $this->release($files);

            throw $error;
        } finally {
            $zip->close();
            @unlink($local);
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
        $source = $this->disk()->readStream($archive);
        $target = fopen($copy, 'wb');

        if ($source === null || $target === false) {
            @unlink($copy);

            throw new LogicException("The kept zip {$archive} cannot be read.");
        }

        stream_copy_to_stream($source, $target);
        fclose($target);

        if (is_resource($source)) {
            fclose($source);
        }

        return $copy;
    }
}
