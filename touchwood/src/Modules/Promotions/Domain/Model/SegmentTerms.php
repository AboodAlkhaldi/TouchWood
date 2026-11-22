<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Enums\SegmentAudience;
use Modules\Promotions\Public\Enums\SegmentRule;
use Modules\Promotions\Public\Enums\SegmentType;

/**
 * Everything staff set on a segment (promotions.md §1.5, §5), checked as it is built — as the
 * database's CHECKs hold it: a hand-picked segment has no rule; a dynamic one exactly one, with its own
 * numbers — months 1 to 120, orders 1 to 1000.
 */
final readonly class SegmentTerms
{
    private function __construct(
        public string $nameAr,
        public string $nameEn,
        public SegmentType $type,
        public SegmentAudience $audience,
        public ?SegmentRule $rule,
        public ?int $ruleMonths,
        public ?int $ruleOrders,
    ) {}

    /**
     * @throws InvalidPromotionAttribute
     */
    public static function of(string $nameAr, string $nameEn, string $type, string $audience, ?string $rule, ?int $ruleMonths, ?int $ruleOrders): self
    {
        $type = SegmentType::tryFrom($type) ?? throw new InvalidPromotionAttribute('segment_type', 'STATIC or DYNAMIC');
        $audience = SegmentAudience::tryFrom($audience) ?? throw new InvalidPromotionAttribute('audience', 'INDIVIDUAL or COMPANY');

        if ($type === SegmentType::Static) {
            if ($rule !== null || $ruleMonths !== null || $ruleOrders !== null) {
                throw new InvalidPromotionAttribute('rule', 'a hand-picked segment has no rule');
            }

            return new self(self::name($nameAr), self::name($nameEn), $type, $audience, null, null, null);
        }

        $rule = SegmentRule::tryFrom((string) $rule) ?? throw new InvalidPromotionAttribute('rule', 'NO_ORDERS_EVER, NO_ORDER_FOR or AT_LEAST_ORDERS');

        if ($rule === SegmentRule::NoOrdersEver && ($ruleMonths !== null || $ruleOrders !== null)) {
            throw new InvalidPromotionAttribute('rule', 'never ordered takes no numbers');
        }

        if ($rule !== SegmentRule::NoOrdersEver && ($ruleMonths === null || $ruleMonths < 1 || $ruleMonths > 120)) {
            throw new InvalidPromotionAttribute('rule_months', 'from 1 to 120 months');
        }

        if ($rule === SegmentRule::NoOrderFor && $ruleOrders !== null) {
            throw new InvalidPromotionAttribute('rule_orders', 'no order for some months takes no number of orders');
        }

        if ($rule === SegmentRule::AtLeastOrders && ($ruleOrders === null || $ruleOrders < 1 || $ruleOrders > 1000)) {
            throw new InvalidPromotionAttribute('rule_orders', 'from 1 to 1000 orders');
        }

        return new self(self::name($nameAr), self::name($nameEn), $type, $audience, $rule, $ruleMonths, $ruleOrders);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function snapshot(): array
    {
        return [
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'type' => $this->type->value,
            'audience' => $this->audience->value,
            'rule' => $this->rule?->value,
            'rule_months' => $this->ruleMonths,
            'rule_orders' => $this->ruleOrders,
        ];
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length < 1 || $length > 100) {
            throw new InvalidPromotionAttribute('name', '1 to 100 characters');
        }

        return $name;
    }
}
