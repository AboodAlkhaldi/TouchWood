<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\UpdateSetting;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Exception\UnknownSetting;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Events\SettingChanged;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Changes one setting. The permission comes from the setting's own definition, and is checked
 * against the store for a per-store setting or with no store — all-stores access — for a
 * global one (Platform spec §3).
 */
final readonly class UpdateSettingHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InMemorySettingsRegistry $registry,
        private StoreDirectory $directory,
        private SettingValues $values,
        private AuditLog $auditLog,
        private ActorContext $actors,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function handle(UpdateSetting $command): void
    {
        $definition = $this->registry->definition($command->key) ?? throw new UnknownSetting($command->key);

        ReadSetting::assertScope($definition, $command->storeCode !== null);

        $store = $command->storeCode === null ? null : $this->storeId($command->storeCode);

        // A global setting reaches every store, so changing it needs the permission everywhere.
        $this->authorizer->authorize(
            $definition->permission,
            $store === null ? PermissionScope::allStores() : PermissionScope::store($store),
        );

        $value = $command->value;

        if (! $this->isJsonValue($value)) {
            throw new InvalidSettingValue($command->key, 'only numbers, text, true/false, lists and empty values can be stored');
        }

        $problem = $this->registry->validationProblem($definition, $value);

        if ($problem !== null) {
            throw new InvalidSettingValue($command->key, $problem);
        }

        $this->db->transaction(function () use ($command, $definition, $store, $value) {
            $current = $this->values->lockForUpdate($command->key, $store?->value);
            $previous = $current === null ? $definition->default : $current->value;

            if ($current !== null && $current->value === $value) {
                return;
            }

            $actor = $this->actors->current();
            $id = $this->values->save($command->key, $store?->value, $value, $actor->type === ActorType::Staff ? $actor->id : null);
            $this->values->invalidate();

            $this->auditLog->record(new AuditEntryDto(
                'platform.setting.updated',
                'platform.setting',
                (string) $id,
                $store?->value,
                // The subject is the row id; the key is repeated so the entry reads on its own.
                AuditChanges::none()
                    ->changed('key', $command->key, $command->key)
                    ->changed('value', $this->isJsonValue($previous) ? $previous : null, $value),
            ));

            $this->events->dispatch(new SettingChanged((string) Str::uuid(), $command->key, $store?->value, CarbonImmutable::now()));
        });
    }

    private function storeId(string $code): StoreId
    {
        $store = $this->directory->storeByCode($code) ?? throw new StoreNotFound($code);

        return $store->storeId();
    }

    /**
     * @phpstan-assert-if-true bool|int|float|string|array<array-key, bool|int|float|string|null>|null $value
     */
    private function isJsonValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! is_scalar($item) && $item !== null) {
                    return false;
                }
            }

            return true;
        }

        return is_scalar($value) || $value === null;
    }
}
