<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use Modules\Access\Domain\Model\Address;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Public\Dto\AddressDto;

/**
 * An address as other modules see it (spec §2.4): its store's layout applied, and whether it still
 * satisfies that store's format — a format that asked for a new field leaves older addresses
 * incomplete, and an incomplete address may not be used for an order (amendment 41).
 *
 * A store's format is read once per store, however many addresses are mapped.
 */
final class AddressMapper
{
    /** @var array<string, ?StoreAddressFormat> */
    private array $formats = [];

    public function __construct(private readonly StoreAddressFormatRepository $formatsRepository) {}

    public function toDto(Address $address): AddressDto
    {
        $format = $this->format($address->storeId());
        // In the store's own order: jsonb keeps no key order, so the row's is whatever it liked.
        $fields = $format === null ? $address->fields() : $format->order($address->fields());

        return new AddressDto(
            $address->id(),
            $address->customerId(),
            $address->storeId(),
            $address->label(),
            $address->recipientName(),
            $address->phone()->value,
            $fields,
            $address->pin()?->latitude,
            $address->pin()?->longitude,
            $address->isDefault(),
            $format?->satisfiedBy($fields) ?? false,
            $format?->render($fields) ?? '',
        );
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<AddressDto>
     */
    public function toDtos(array $addresses): array
    {
        return array_map(fn (Address $address): AddressDto => $this->toDto($address), $addresses);
    }

    private function format(string $storeId): ?StoreAddressFormat
    {
        return $this->formats[$storeId] ??= $this->formatsRepository->forStore($storeId);
    }
}
