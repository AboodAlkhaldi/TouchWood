<?php

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\SettingScope;

/**
 * A configurable value a module declares at boot (Platform spec §1.3).
 */
final readonly class SettingDefinitionDto
{
    /**
     * @param  string  $key  "{module}.{area}.{name}", e.g. "loyalty.points.expiry_days"
     * @param  list<mixed>  $rules  Laravel validation rules the value must pass, e.g. ['integer', 'min:1']
     * @param  mixed  $default  returned while nothing is stored; must itself pass the rules
     * @param  string  $permission  the permission needed to change it, e.g. "loyalty.settings.update"
     */
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public array $rules,
        public mixed $default,
        public string $permission,
    ) {}
}
