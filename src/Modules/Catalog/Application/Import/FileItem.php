<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One item of a store file, as read and checked (the guide, §2).
 */
final readonly class FileItem
{
    /**
     * @param  int  $number  its place in the file, counted from 1
     * @param  string  $price  as the file wrote it; shown, not kept until stage 5
     */
    public function __construct(
        public int $number,
        public string $code,
        public string $price,
        public ?int $stock,
    ) {}
}
