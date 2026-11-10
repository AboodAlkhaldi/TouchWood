<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadRedirectSheet;

/**
 * W11's Upload Sheet (content.md §1.15): the file as it arrived - where it is on the server, its name as sent (its
 * type is read from it) and its size.
 */
final readonly class UploadRedirectSheet
{
    public function __construct(
        public string $path,
        public string $fileName,
        public int $bytes,
    ) {}
}
