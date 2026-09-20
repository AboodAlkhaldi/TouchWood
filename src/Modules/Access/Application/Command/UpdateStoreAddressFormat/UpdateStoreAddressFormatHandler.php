<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateStoreAddressFormat;

use Illuminate\Database\Connection;
use Modules\Access\Application\Address\StoreIds;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Domain\ValueObject\AddressField;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Staff change one country's address form (spec §3.3). Only that store's copy moves: the other
 * countries keep theirs, which is the whole point of a format per store (handoff §7.8).
 *
 * Addresses already saved keep their values; one that no longer satisfies the new format cannot be
 * used for an order until the customer completes it (amendment 41, `AddressDto::isComplete`).
 */
final readonly class UpdateStoreAddressFormatHandler
{
    public const string PERMISSION = AccessPermissions::ADDRESS_FORMAT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StoreAddressFormatRepository $formats,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidAddress|InvalidAccessAttribute
     */
    public function handle(UpdateStoreAddressFormat $command): void
    {
        $store = StoreIds::of($command->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $storeId = $store->value;

        if ($this->platform->store($store) === null) {
            throw new InvalidAccessAttribute('store', 'unknown');
        }

        $fields = array_map(static fn (array $field): AddressField => AddressField::fromArray($field), $command->fields);

        $format = StoreAddressFormat::of($storeId, $fields, $command->displayTemplate);

        $this->db->transaction(function () use ($format, $storeId): void {
            $before = $this->formats->forStore($storeId);

            // Inside the transaction: the cached copy is replaced exactly when this commits.
            $this->formats->save($format);

            $this->platform->recordAudit(new AuditEntryDto(
                'access.store_address_format.updated',
                'access.store_address_format',
                $storeId,
                $storeId,
                // The keys, not the labels: an audit entry says which fields a store now asks for.
                AuditChanges::none()
                    ->changed('fields', $before === null ? null : self::keys($before), self::keys($format))
                    ->changed('required_fields', $before === null ? null : self::keys($before, true), self::keys($format, true))
                    ->changed('display_template', $before?->displayTemplate, $format->displayTemplate),
            ));
        }, 3);
    }

    /**
     * @return list<string>
     */
    private static function keys(StoreAddressFormat $format, bool $requiredOnly = false): array
    {
        $keys = [];

        foreach ($format->fields as $field) {
            if (! $requiredOnly || $field->required) {
                $keys[] = $field->key;
            }
        }

        return $keys;
    }
}
