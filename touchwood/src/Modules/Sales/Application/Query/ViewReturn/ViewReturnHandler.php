<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewReturn;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use Modules\Sales\Application\Query\Staff\StaffReturnView;
use Modules\Sales\Application\Return\ReturnAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\ReturnNotFound;
use Modules\Sales\Public\Enums\ReturnReason;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/** **A return as staff see it** (sales.md §3 ViewReturn); one of a store the reader does not cover is no return. */
final readonly class ViewReturnHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public function __construct(
        private ReturnAccess $access,
        private StaffReturnReads $reads,
        private StaffReader $reader,
    ) {}

    /**
     * @throws Unauthorized|ReturnNotFound
     */
    public function handle(ViewReturn $query): StaffReturnView
    {
        [$returnId] = $this->access->authorize(self::PERMISSION, $query->returnId);
        $return = $this->reads->returnOf($returnId) ?? throw new ReturnNotFound;
        $at = static fn (mixed $moment): ?CarbonImmutable => $moment === null ? null : CarbonImmutable::parse((string) $moment);
        $decidedBy = $return['decided_by'] === null ? null : (string) $return['decided_by'];
        $names = $this->reader->staff([$decidedBy]);

        return new StaffReturnView(
            $returnId, (string) $return['order_id'], (string) $return['number'], (string) $return['store_id'],
            $this->reader->customers([(string) $return['customer_id']])[(string) $return['customer_id']],
            (string) $return['status'], ReturnReason::from((string) $return['reason']),
            $return['reason_other'] === null ? null : (string) $return['reason_other'], $return['staff_reason'] === null ? null : (string) $return['staff_reason'],
            CarbonImmutable::parse((string) $return['requested_at']), $decidedBy === null ? null : ($names[strtolower($decidedBy)] ?? null), $at($return['decided_at']),
            $at($return['withdrawn_at']), $at($return['received_at']), $at($return['closed_at']), $at($return['completed_at']),
            $return['refund_due_minor'] === null ? null : Money::of((int) $return['refund_due_minor'], (string) $return['currency_code']),
            (bool) $return['shipping_refunded'],
            array_map(static function (array $line): array {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = json_decode((string) $line['variant_values'], true, 512, JSON_THROW_ON_ERROR);

                return [
                    'orderLineId' => (string) $line['order_line_id'], 'variantId' => (string) $line['variant_id'], 'nameAr' => (string) $line['name_ar'],
                    'nameEn' => (string) $line['name_en'], 'values' => $values, 'code' => (string) $line['code'], 'gift' => (bool) $line['is_gift'],
                    'asked' => (int) $line['quantity'], 'received' => $line['received_quantity'] === null ? null : (int) $line['received_quantity'],
                    'restocked' => $line['restocked_quantity'] === null ? null : (int) $line['restocked_quantity'],
                ];
            }, $this->reads->lines($returnId)),
            array_map(static fn (array $photo): array => ['mediaId' => (string) $photo['media_id'], 'position' => (int) $photo['position']], $this->reads->photos($returnId)),
            $this->reader->exponent((string) $return['currency_code']),
        );
    }
}
