<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * A configurable value a module declares at boot (Platform spec §1.3).
 *
 * Never a secret: API keys, passwords and credentials live only in server environment variables
 * (owner's decision, 2026-09-18), and a key that looks like one is refused at boot.
 */
final readonly class SettingDefinitionDto
{
    /**
     * @param  string  $key  "{module}.{area}.{name}", e.g. "loyalty.points.expiry_days"
     * @param  SettingType  $type  checked strictly before the rules run
     * @param  list<mixed>  $rules  further Laravel validation rules, e.g. ['min:1']
     * @param  mixed  $default  returned while nothing is stored; must itself pass the type and rules
     * @param  string  $permission  the permission needed to change it, e.g. "loyalty.settings.update"
     * @param  bool  $sensitive  the audit log records only that it changed, never its values
     */
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public SettingType $type,
        public array $rules,
        public mixed $default,
        public string $permission,
        public bool $sensitive = false,
    ) {}

    /**
     * Where the setting's name is written for a person to read, in the words of the module that
     * declared it: "access.staff.lockout_minutes" is read from access::settings.staff.lockout_minutes.
     *
     * The same shape as a permission's label (PermissionDefinitionDto), because it answers the same
     * question - what to call this thing on a screen.
     */
    public function labelKey(): string
    {
        [$module, $rest] = explode('.', $this->key, 2) + [1 => ''];

        return "{$module}::settings.{$rest}";
    }

    /**
     * The bounds the module allows, taken from its own rules, or null where it set none.
     *
     * A number the screen offers outside these would be refused on save (Access amendment 22 asks
     * for the ranges to be shown), and reading them from the rules keeps the screen and the
     * refusal saying the same thing.
     *
     * @return array{min: int|null, max: int|null}
     */
    public function bounds(): array
    {
        $bounds = ['min' => null, 'max' => null];

        foreach ($this->rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            foreach (['min', 'max'] as $edge) {
                if (str_starts_with($rule, "{$edge}:") && ctype_digit(substr($rule, strlen($edge) + 1))) {
                    $bounds[$edge] = (int) substr($rule, strlen($edge) + 1);
                }
            }
        }

        return $bounds;
    }
}
