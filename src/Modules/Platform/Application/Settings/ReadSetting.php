<?php

namespace Modules\Platform\Application\Settings;

use Modules\Platform\Domain\Exception\SettingScopeMismatch;
use Modules\Platform\Domain\Exception\UnknownSetting;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Dto\SettingValueDto;
use Modules\Platform\Public\Enums\SettingScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * A setting's value for other modules: the stored one, otherwise the definition's default.
 * Reading a declared key with the right scope never fails.
 */
final readonly class ReadSetting
{
    public function __construct(
        private InMemorySettingsRegistry $registry,
        private SettingValues $values,
    ) {}

    public function __invoke(string $key, ?StoreId $store): SettingValueDto
    {
        $definition = $this->registry->definition($key) ?? throw new UnknownSetting($key);

        self::assertScope($definition, $store !== null);

        $stored = $this->values->find($key, $store?->value);

        return new SettingValueDto($key, $store?->value, $stored === null, $stored === null ? $definition->default : $stored->value);
    }

    public static function assertScope(SettingDefinitionDto $definition, bool $hasStore): void
    {
        if ($definition->scope === SettingScope::Store && ! $hasStore) {
            throw new SettingScopeMismatch($definition->key, 'set per store, so a store is required');
        }

        if ($definition->scope === SettingScope::Global && $hasStore) {
            throw new SettingScopeMismatch($definition->key, 'global, so no store may be given');
        }
    }
}
