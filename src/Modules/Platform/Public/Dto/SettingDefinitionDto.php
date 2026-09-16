<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * A configurable value a module declares at boot (Platform spec §1.3).
 */
final readonly class SettingDefinitionDto
{
    /**
     * @param  string  $key  "{module}.{area}.{name}", e.g. "loyalty.points.expiry_days"
     * @param  SettingType  $type  checked strictly before the rules run
     * @param  list<mixed>  $rules  further Laravel validation rules, e.g. ['min:1']
     * @param  mixed  $default  returned while nothing is stored; must itself pass the type and rules
     * @param  string  $permission  the permission needed to change it, e.g. "loyalty.settings.update"
     */
    public function __construct(
        public string $key,
        public SettingScope $scope,
        public SettingType $type,
        public array $rules,
        public mixed $default,
        public string $permission,
    ) {}
}
