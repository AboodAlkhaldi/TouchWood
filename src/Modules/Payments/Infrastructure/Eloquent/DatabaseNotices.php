<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Model\KeptNotice;
use Modules\Payments\Domain\Repository\Notices;
use Modules\Payments\Public\Enums\PaymentMode;
use stdClass;

final readonly class DatabaseNotices implements Notices
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $storeId, PaymentMode $mode, string $reference, array $payload, DateTimeImmutable $now): ?string
    {
        $added = $this->db->table('payments.notices')->insertOrIgnore([
            'gateway' => 'MYFATOORAH',
            'store_id' => $storeId,
            'mode' => $mode->value,
            'event_reference' => $reference,
            'received_at' => $now,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $added === 1
            ? (string) $this->db->table('payments.notices')->where('gateway', 'MYFATOORAH')->where('mode', $mode->value)->where('event_reference', $reference)->value('id')
            : null;
    }

    public function find(string $id): ?KeptNotice
    {
        $row = $this->db->table('payments.notices')->where('id', $id)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var array<array-key, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, 32, JSON_THROW_ON_ERROR);

        return new KeptNotice(
            (string) $row->id,
            (string) $row->store_id,
            PaymentMode::from((string) $row->mode),
            $payload,
            $row->handled_at === null ? null : (new CarbonImmutable((string) $row->handled_at))->toDateTimeImmutable(),
        );
    }

    public function markHandled(string $id, DateTimeImmutable $now): void
    {
        $this->db->table('payments.notices')->where('id', $id)->update(['handled_at' => $now]);
    }

    public function unhandled(DateTimeImmutable $before, int $most): array
    {
        $ids = [];

        foreach ($this->db->table('payments.notices')->whereNull('handled_at')->where('received_at', '<', $before)->orderBy('received_at')->limit($most)->pluck('id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }
}
