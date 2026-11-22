<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CloneRole;

/**
 * A new saved role copied from a saved one: a copy at creation time, never live inheritance
 * (handoff §7.5). Refused when the source holds an action the author does not (owner, 2026-09-19).
 */
final readonly class CloneRole
{
    public function __construct(
        public string $sourceRoleId,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
