<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Repository;

use Modules\Platform\Domain\Exception\CurrencyAlreadyExists;
use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Domain\ValueObject\CurrencyCode;

interface CurrencyRepository
{
    public function byCode(CurrencyCode $code): ?Currency;

    public function exists(CurrencyCode $code): bool;

    public function isUsedByAnyStore(CurrencyCode $code): bool;

    /**
     * @throws CurrencyAlreadyExists when a new currency's code was taken first
     */
    public function add(Currency $currency): void;

    public function update(Currency $currency): void;
}
