<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\PermissionAudience;

/**
 * A permission a module checks, declared once at boot (Access spec §2.2).
 *
 * Its name in Arabic and English is the module's translation at labelKey(), read only when a
 * screen shows it, so declaring permissions costs nothing on an ordinary request.
 */
final readonly class PermissionDefinitionDto
{
    /**
     * @param  string  $name  "{module}.{resource}.{action}", e.g. "catalog.product.update"
     * @param  bool  $reserved  Super Admins only; never offered in the role editor
     */
    public function __construct(
        public string $name,
        public PermissionAudience $audience = PermissionAudience::Role,
        public bool $reserved = false,
    ) {}

    /**
     * "catalog.product.update" → "catalog::permissions.product.update".
     */
    public function labelKey(): string
    {
        [$module, $rest] = explode('.', $this->name, 2) + [1 => ''];

        return "{$module}::permissions.{$rest}";
    }
}
