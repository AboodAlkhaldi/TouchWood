<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * An uploaded zip, opened (the guide, §1.6): its `products.json`, and the files beside it with their
 * sizes once unpacked.
 */
final readonly class ImportArchive
{
    /**
     * @param  array<string, int>  $files  path inside the zip => its size unpacked, in bytes
     */
    public function __construct(
        public string $json,
        public array $files,
    ) {}
}
