<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOwnReturn;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Query\Own\OwnReader;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\Query\Own\OwnReturnView;
use Modules\Sales\Application\Query\Own\ReturnLineView;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * **The customer's own return** (sales.md §3 ViewOwnReturn): its order's number, where it stands, the reason
 * they gave, staff's words when refused (§1.8 — the customer sees them), its times, the refund once completed,
 * its lines and its photos' count. Another customer's return is no return.
 */
final readonly class ViewOwnReturnHandler
{
    public function __construct(
        private OwnReader $reader,
        private OwnReads $reads,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound
     */
    public function handle(ViewOwnReturn $query): OwnReturnView
    {
        $customerId = $this->reader->authorize();
        $id = strtolower(trim($query->returnId));
        $return = (Ulids::valid($id) ? $this->reads->returnOf($customerId, $id) : null) ?? throw new ReturnNotFound;
        $at = static fn (mixed $moment): ?CarbonImmutable => $moment === null ? null : CarbonImmutable::parse((string) $moment);

        return new OwnReturnView(
            (string) $return['id'], (string) $return['number'], (string) $return['status'], (string) $return['reason'],
            $return['reason_other'] === null ? null : (string) $return['reason_other'],
            $return['staff_reason'] === null ? null : (string) $return['staff_reason'],
            CarbonImmutable::parse((string) $return['requested_at']), $at($return['decided_at']), $at($return['received_at']), $at($return['completed_at']),
            $return['refund_due_minor'] === null ? null : Money::of((int) $return['refund_due_minor'], (string) $return['currency_code']),
            array_map(static function (array $line): ReturnLineView {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = json_decode((string) $line['variant_values'], true, 512, JSON_THROW_ON_ERROR);

                return new ReturnLineView(
                    (string) $line['name_ar'], (string) $line['name_en'], $values, (string) $line['code'], (bool) $line['is_gift'],
                    (int) $line['quantity'], $line['received_quantity'] === null ? null : (int) $line['received_quantity'],
                );
            }, $this->reads->returnLines($id)),
            $this->reads->returnPhotos($id),
        );
    }
}
