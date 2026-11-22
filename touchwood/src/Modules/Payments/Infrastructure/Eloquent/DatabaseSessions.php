<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\GatewaySession;
use Modules\Payments\Domain\Repository\Sessions;
use Modules\Payments\Public\Enums\PaymentMode;
use stdClass;

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

    public function lock(PaymentMode $mode, string $id): ?GatewaySession
    {
        return self::session($this->db->table('payments.sessions')->where('gateway', 'MYFATOORAH')->where('mode', $mode->value)->where('id', $id)->lockForUpdate()->first());
    }

    public function markUsed(PaymentMode $mode, string $id, string $attemptId): void
    {
        $this->db->table('payments.sessions')->where('gateway', 'MYFATOORAH')->where('mode', $mode->value)->where('id', $id)->update(['used_by' => $attemptId]);
    }

    public function reusable(string $storeId, PaymentMode $mode, string $ownerId, string $buyerId, int $amountMinor, string $currencyCode, DateTimeImmutable $now): ?GatewaySession
    {
        return self::session($this->db->table('payments.sessions')
            ->where('store_id', $storeId)
            ->where('mode', $mode->value)
            ->where('owner_id', $ownerId)
            ->where('buyer_id', $buyerId)
            ->where('amount_minor', $amountMinor)
            ->where('currency_code', $currencyCode)
            ->whereNull('used_by')
            ->where('expires_at', '>', $now)
            ->orderByDesc('expires_at')
            ->first());
    }

    private static function session(mixed $row): ?GatewaySession
    {
        return $row instanceof stdClass ? new GatewaySession(
            (string) $row->id,
            (string) $row->store_id,
            PaymentMode::from((string) $row->mode),
            (string) $row->owner_id,
            (string) $row->buyer_id,
            (int) $row->amount_minor,
            (string) $row->currency_code,
            (new CarbonImmutable((string) $row->expires_at))->toDateTimeImmutable(),
            $row->used_by === null ? null : (string) $row->used_by,
        ) : null;
    }
}
