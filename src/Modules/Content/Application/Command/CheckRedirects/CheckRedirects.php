<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\CheckRedirects;

/** One page of Check Addresses, after this old address (none: the first page). */
final readonly class CheckRedirects
{
    public function __construct(
        public ?string $after = null,
    ) {}
}
