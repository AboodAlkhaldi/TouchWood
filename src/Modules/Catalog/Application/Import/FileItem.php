<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One item of a store file, as read and checked (the guide, §2): a wired store's has its code alone
 * (amendment 43), a store not wired's its price and, if given, its stock.
 */
final readonly class FileItem
{
    /**
     * @param  int  $number  its place in the file, counted from 1
     * @param  string|null  $price  as the file wrote it; none in a wired store's file
     */
    public function __construct(
        public int $number,
        public string $code,
        public ?string $price,
        public ?int $stock,
    ) {}
}
