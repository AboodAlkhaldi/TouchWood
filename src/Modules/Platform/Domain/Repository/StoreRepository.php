<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use Modules\Platform\Domain\Exception\StoreCodeTaken;
use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Shared\Domain\ValueObject\StoreId;

interface StoreRepository
{
    public function nextId(): StoreId;

    public function byCode(StoreCode $code): ?Store;

    public function codeExists(StoreCode $code): bool;

    /**
     * @throws StoreCodeTaken when another store took the code first
     */
    public function add(Store $store): void;

    public function update(Store $store): void;
}
