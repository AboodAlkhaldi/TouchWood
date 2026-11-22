<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Public\Contracts\PaymentOrderNumbers;

final readonly class DatabasePaymentOrderNumbers implements PaymentOrderNumbers
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function numbers(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        /** @var array<string, string> $numbers */
        $numbers = $this->db->table('sales.orders')->whereIn('id', $orderIds)->pluck('number', 'id')->all();

        return $numbers;
    }
}
