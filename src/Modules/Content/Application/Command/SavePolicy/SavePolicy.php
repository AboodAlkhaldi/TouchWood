<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SavePolicy;

use Modules\Content\Application\Policies\PolicyInput;

/** A policy's form saved (flags-2026-11.md S3.7): a new draft, or the one it is, at the version the form read. */
final readonly class SavePolicy
{
    public function __construct(
        public PolicyInput $input,
    ) {}
}
