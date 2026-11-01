<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\GatewaySession;
use Modules\Payments\Domain\Repository\Sessions;

final readonly class DatabaseSessions implements Sessions
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(GatewaySession $session): void
    {
        $this->db->table('payments.sessions')->insert([
            'gateway' => 'MYFATOORAH',
            'mode' => $session->mode->value,
            'id' => $session->id,
            'store_id' => $session->storeId,
            'owner_id' => $session->ownerId,
            'buyer_id' => $session->buyerId,
            'amount_minor' => $session->amountMinor,
            'currency_code' => $session->currencyCode,
            'expires_at' => $session->expiresAt,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
