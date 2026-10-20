<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Sales\Domain\Model\MoneyRecord;
use Modules\Sales\Domain\Repository\MoneyRecords;
use stdClass;

/** `MoneyRecords` in SQL, no Eloquent model (sales.md §5): `sales.payments` and `sales.refunds`. */
final readonly class DatabaseMoneyRecords implements MoneyRecords
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byRequest(string $kind, string $requestId): ?MoneyRecord
    {
        $row = $this->db->table(self::table($kind))->where('request_id', strtolower($requestId))->first();

        return $row instanceof stdClass ? self::record($kind, $row) : null;
    }

    public function add(MoneyRecord $record): void
    {
        $this->db->table(self::table($record->kind))->insert([
            'id' => $record->id,
            'order_id' => $record->orderId,
            'store_id' => $record->storeId,
            'currency_code' => $record->currencyCode,
            'amount_minor' => $record->amount,
            'way' => $record->way,
            'reference' => $record->reference,
            'note' => $record->note,
            'happened_on' => $record->happenedOn->format('Y-m-d'),
            'request_id' => strtolower($record->requestId),
            'recorded_by' => $record->recordedBy,
            'recorded_at' => Moment::of($record->recordedAt),
        ]);
    }

    public function orderOf(string $kind, string $recordId): ?string
    {
        $order = $this->db->table(self::table($kind))->where('id', strtolower($recordId))->value('order_id');

        return $order === null ? null : (string) $order;
    }

    public function lock(string $kind, string $recordId): ?MoneyRecord
    {
        $row = $this->db->table(self::table($kind))->where('id', strtolower($recordId))->lockForUpdate()->first();

        return $row instanceof stdClass ? self::record($kind, $row) : null;
    }

    public function void(string $kind, string $recordId, string $by, string $reason, DateTimeImmutable $at): void
    {
        $this->db->table(self::table($kind))->where('id', $recordId)->update(['voided_by' => $by, 'voided_at' => Moment::of($at), 'void_reason' => $reason]);
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            MoneyRecord::PAYMENT => 'sales.payments',
            MoneyRecord::REFUND => 'sales.refunds',
            default => throw new InvalidArgumentException("No money records of the kind {$kind}."),
        };
    }

    private static function record(string $kind, stdClass $row): MoneyRecord
    {
        return new MoneyRecord(
            $kind,
            (string) $row->id,
            (string) $row->order_id,
            (string) $row->store_id,
            (string) $row->currency_code,
            (int) $row->amount_minor,
            (string) $row->way,
            $row->reference === null ? null : (string) $row->reference,
            $row->note === null ? null : (string) $row->note,
            CarbonImmutable::parse((string) $row->happened_on),
            (string) $row->request_id,
            $row->recorded_by === null ? null : (string) $row->recorded_by,
            CarbonImmutable::parse((string) $row->recorded_at),
            $row->voided_at !== null,
        );
    }
}
