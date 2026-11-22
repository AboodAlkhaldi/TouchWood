<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\AddressFormats;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\MyAccount\AddressFieldDto;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * The stores whose address form this person may change, and what each one holds (spec §1.9, §3.3).
 *
 * The permission is per store, so the screen is told which stores it may offer rather than working
 * it out from a role it cannot see - and asking the authorizer from a screen would be deciding,
 * which a test forbids.
 */
final readonly class AddressFormatsForStaff
{
    public const string PERMISSION = AccessPermissions::ADDRESS_FORMAT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private StoreAddressFormatRepository $formats,
    ) {}

    /**
     * The stores this person may change, or null for somebody limited by nothing at all - which is
     * every store, including one opened tomorrow.
     *
     * @return list<string>|null
     */
    public function stores(): ?array
    {
        $stores = $this->authorizer->storesWith(self::PERMISSION);

        return $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
    }

    /**
     * Which of these stores have a format at all, by id.
     *
     * The ids come from {@see stores()}, so this asks no permission of its own: it is the same
     * question, answered for the list the caller was just given.
     *
     * @param  list<string>  $storeIds
     * @return array<string, bool>
     */
    public function existFor(array $storeIds): array
    {
        $exists = [];

        foreach ($storeIds as $storeId) {
            $exists[$storeId] = $this->formats->existsFor($storeId);
        }

        return $exists;
    }

    /**
     * One store's form. Refuses anybody who may not change that store's, so the screen cannot be
     * used to read a format they have no business in.
     */
    public function forStore(string $storeId): AddressFormatDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store(StoreId::fromString($storeId)));

        $format = $this->formats->forStore($storeId);

        if ($format === null) {
            return new AddressFormatDto($storeId, false, [], [], '');
        }

        $fields = [];
        $orders = [];

        foreach ($format->fields as $field) {
            $fields[] = new AddressFieldDto($field->key, $field->labelAr, $field->labelEn, $field->required, $field->maxLength);
            $orders[$field->key] = $field->order;
        }

        return new AddressFormatDto($storeId, true, $fields, $orders, $format->displayTemplate);
    }
}
