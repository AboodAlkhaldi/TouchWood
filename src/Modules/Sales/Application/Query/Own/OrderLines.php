<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use Carbon\CarbonImmutable;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Returns;
use Shared\Domain\ValueObject\Money;

/**
 * An order's lines as sold, for the customer's view and staff's alike — each with the pieces still to ask back
 * now (sales.md §1.8: within the window, in whole seconds, less what other returns count).
 */
final readonly class OrderLines
{
    public function __construct(
        private OwnReads $reads,
        private Returns $returns,
    ) {}

    /**
     * @param  array<string, mixed>  $order  the order's row
     * @return array{list<OrderLineView>, bool} the lines, and whether the return window is open now
     */
    public function of(array $order): array
    {
        $currency = (string) $order['currency_code'];
        $deadline = $order['return_deadline_at'] === null ? null : CarbonImmutable::parse((string) $order['return_deadline_at']);
        $inWindow = $order['status'] === OrderState::COMPLETED && $deadline !== null && CarbonImmutable::now()->startOfSecond() <= $deadline;
        $counted = $inWindow ? $this->returns->returnable((string) $order['id']) : [];
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, $currency);

        return [array_map(static function (array $line) use ($money, $counted): OrderLineView {
            /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
            $values = json_decode((string) $line['variant_values'], true, 512, JSON_THROW_ON_ERROR);
            $held = $counted[(string) $line['id']] ?? null;

            return new OrderLineView(
                (string) $line['id'], (string) $line['product_id'], (string) $line['variant_id'], (string) $line['name_ar'], (string) $line['name_en'],
                $values, (string) $line['code'], (string) $line['sale_mode'], (bool) $line['is_gift'], (int) $line['quantity'],
                $money($line['unit_minor']), $money($line['list_unit_minor']), $money($line['total_minor']),
                $held === null ? 0 : max(0, $held['quantity'] - $held['counted']),
            );
        }, $this->reads->lines((string) $order['id'])), $inWindow];
    }
}
