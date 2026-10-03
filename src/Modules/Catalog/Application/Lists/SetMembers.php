<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Model\Attribute;
use Modules\Catalog\Domain\Model\AttributeSet;
use Modules\Catalog\Domain\Repository\AttributeRepository;

/**
 * An attribute set's members as the form sends them — ids, in order — read under the attributes'
 * lock, so the set checks them as they are now.
 */
final readonly class SetMembers
{
    public function __construct(
        private AttributeRepository $attributes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributeIds  as the request sent them
     * @return list<Attribute>
     *
     * @throws InvalidCatalogAttribute|ListItemNotFound
     */
    public function read(array $attributeIds): array
    {
        // Refused before any is read: the set refuses as many anyway, and a long list would
        // otherwise be read row by row under the lock first.
        if (count($attributeIds) > AttributeSet::MAX_MEMBERS) {
            throw new InvalidCatalogAttribute('attribute_ids', 'at most '.AttributeSet::MAX_MEMBERS.' attributes');
        }

        $members = [];

        foreach ($attributeIds as $id) {
            if (! is_string($id)) {
                throw new InvalidCatalogAttribute('attribute_ids', 'attributes, by their ids');
            }

            $members[] = $this->attributes->byId($id) ?? throw new ListItemNotFound($id);
        }

        return $members;
    }
}
