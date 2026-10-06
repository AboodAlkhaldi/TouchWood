<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\Warranty;

interface WarrantyRepository
{
    public function nextId(): string;

    public function find(string $warrantyId): ?Warranty;

    public function byId(string $warrantyId): ?Warranty;

    public function add(Warranty $warranty): void;

    public function update(Warranty $warranty): void;

    public function delete(string $warrantyId): void;

    /**
     * @return list<Warranty>
     */
    public function all(): array;
}
