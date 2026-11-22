<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A customer keeps at most the store's number of addresses (amendment 41: ten by default).
 */
final class TooManyAddresses extends AccessError
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("An address book keeps at most {$limit} addresses in one store.");
    }

    public function type(): string
    {
        return 'access.too_many_addresses';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['limit' => $this->limit];
    }
}
