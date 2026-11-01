<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Repository\Notices;
use Modules\Payments\Public\Enums\PaymentMode;

final readonly class DatabaseNotices implements Notices
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $storeId, PaymentMode $mode, string $reference, array $payload, DateTimeImmutable $now): bool
    {
        return $this->db->table('payments.notices')->insertOrIgnore([
            'gateway' => 'MYFATOORAH',
            'store_id' => $storeId,
            'mode' => $mode->value,
            'event_reference' => $reference,
            'received_at' => $now,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]) === 1;
    }
}
