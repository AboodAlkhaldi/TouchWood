<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteRole;

/**
 * Deletes a saved role. When anyone holds it, a replacement — a saved role of the same level — is
 * required and every holder moves to it (owner's decisions, 2026-09-19).
 */
final readonly class DeleteRole
{
    public function __construct(
        public string $roleId,
        public ?string $replacementRoleId = null,
    ) {}
}
