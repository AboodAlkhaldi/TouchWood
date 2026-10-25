<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\OpenOrderFile;

final readonly class OpenOrderFile
{
    public function __construct(
        public string $mediaId,
    ) {}
}
