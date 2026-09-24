<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListSettings;

use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Every setting this person may change (frontend.md §3.5, E4).
 *
 * **A row somebody may not change is not shown to them at all.** Each setting carries its own
 * permission, declared with it by the module that owns it, so this is not one answer for the screen
 * but one answer per row — and the screen is handed only the rows that survived.
 *
 * Where a permission has to hold is decided by the setting's scope, exactly as the handler that
 * writes it decides: a store setting is asked about **the store the panel is on**, and a global one
 * reaches every store at once, so it is asked about **all stores** (Access amendment 5). A person
 * with one store therefore sees their store's settings and none of the global ones.
 *
 * A sensitive setting is never read back (platform.md §1.3). Its value does not appear here even
 * for somebody who may change it: it can be written, and never shown.
 */
final readonly class ListSettingsHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InMemorySettingsRegistry $registry,
        private ReadSetting $read,
    ) {}

    /**
     * @return list<SettingRow>
     */
    public function handle(ListSettings $query): array
    {
        $store = $query->storeId === null ? null : StoreId::fromString($query->storeId);
        $rows = [];

        foreach ($this->registry->all() as $definition) {
            if (! $this->mayChange($definition, $store)) {
                continue;
            }

            $rows[] = $this->row($definition, $store);
        }

        return $rows;
    }

    /**
     * Asked without throwing, because this is a list: a setting they may not change is simply not
     * on it, and the screen never learns that it exists.
     */
    private function mayChange(SettingDefinitionDto $definition, ?StoreId $store): bool
    {
        $reach = $this->authorizer->storesWith($definition->permission);

        // Null from Access means every store, now and for any store added later.
        if ($reach === null) {
            return true;
        }

        if ($definition->scope === SettingScope::Global) {
            // A global setting is one value for the whole system, so holding the permission in
            // some stores is not holding it: only somebody who reaches every store may change it.
            return false;
        }

        if ($store === null) {
            return false;
        }

        foreach ($reach as $reachable) {
            if ($reachable->value === $store->value) {
                return true;
            }
        }

        return false;
    }

    private function row(SettingDefinitionDto $definition, ?StoreId $store): SettingRow
    {
        $scoped = $definition->scope === SettingScope::Store ? $store : null;
        $value = ($this->read)($definition->key, $scoped);
        $bounds = $definition->bounds();

        return new SettingRow(
            $definition->key,
            // "platform.media.max_bytes" belongs to Platform. The module is the first word of the
            // key, which the registry checks when the setting is declared.
            explode('.', $definition->key, 2)[0],
            $definition->scope,
            $definition->type,
            $definition->sensitive ? null : $value->value,
            $definition->sensitive ? null : $definition->default,
            $value->isDefault,
            $definition->sensitive,
            $bounds['min'],
            $bounds['max'],
        );
    }
}
