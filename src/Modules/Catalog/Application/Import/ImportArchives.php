<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Modules\Catalog\Domain\Exception\ImportRefused;

/**
 * A product file's zip (catalog.md §1.12, amendment 6): opened when it is uploaded, kept until its
 * products are brought in, its photos unpacked then into temporary files this class names — never by
 * a name inside the zip, so no entry can write outside them.
 */
interface ImportArchives
{
    /** The zip's limit (the guide, §1.6). */
    public const int MAX_BYTES = 500 * 1024 * 1024;

    /** Everything unpacked may not grow past this: a zip that would is refused before any is. */
    public const int MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * @throws ImportRefused when it is not a zip, is too large, or holds no `products.json` at its top
     */
    public function open(string $zipPath): ImportArchive;

    /**
     * Keeps the uploaded zip for this import, until forget().
     *
     * @return string where it is kept
     */
    public function keep(string $importId, string $zipPath): string;

    /**
     * These photos of a kept zip, unpacked into temporary files.
     *
     * @param  list<string>  $paths  inside the zip
     * @return array<string, string> path inside the zip => temporary file
     */
    public function unpack(string $archive, array $paths): array;

    /**
     * @param  array<string, string>  $files  as unpack() gave them
     */
    public function release(array $files): void;

    public function forget(string $archive): void;
}
