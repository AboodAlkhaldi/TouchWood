<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One item of a store file, as read and checked (the guide, §2): its code, nothing else (amendment 43).
 */
final readonly class FileItem
{
    /**
     * @param  int  $number  its place in the file, counted from 1
     */
    public function __construct(
        public int $number,
        public string $code,
    ) {}
}
