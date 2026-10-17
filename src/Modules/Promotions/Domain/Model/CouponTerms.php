<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Model;

use DateTimeImmutable;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\DiscountKind;

/**
 * Everything staff set on a coupon but its code and store (promotions.md §1.1, §1.2), checked as it is
 * built — every rule in code first, the database's own behind it (handoff §5.3). Amounts are in the
 * store currency's smallest unit; the currency is the store's.
 */
final readonly class CouponTerms
{
    /** The most rules one coupon carries: a list a screen can show and a cart read can walk. */
    public const int MOST_RULES = 500;

    /** The most uses a limit can name: the column's own (`integer`). */
    public const int MOST_USES = 2_147_483_647;

    /**
     * @param  list<CouponRule>  $rules  each once
     */
    private function __construct(
        public string $nameAr,
        public string $nameEn,
        public CouponEligibility $eligibility,
        public DiscountKind $discountKind,
        public ?int $percent,
        public ?int $capMinor,
        public ?int $amountMinor,
        public string $currencyCode,
        public ?int $minimumMinor,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public ?int $maxUsesPerCustomer,
        public ?int $maxUsesTotal,
        public bool $allowWithPoints,
        public bool $onReducedLines,
        public bool $forRetail,
        public bool $forWholesale,
        public bool $forPublic,
        public bool $forCompany,
        public array $rules,
    ) {}

    /**
     * @param  list<array{kind: string, target: string, id: string}>  $rules
     *
     * @throws InvalidPromotionAttribute
     */
    public static function of(
        string $nameAr,
        string $nameEn,
        string $eligibility,
        string $discountKind,
        ?int $percent,
        ?int $capMinor,
        ?int $amountMinor,
        string $currencyCode,
        ?int $minimumMinor,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        ?int $maxUsesPerCustomer,
        ?int $maxUsesTotal,
        bool $allowWithPoints,
        bool $onReducedLines,
        bool $forRetail,
        bool $forWholesale,
        bool $forPublic,
        bool $forCompany,
        array $rules,
    ): self {
        $kind = DiscountKind::tryFrom($discountKind) ?? throw new InvalidPromotionAttribute('discount_kind', 'PERCENT or FIXED');

        if ($kind === DiscountKind::Percent) {
            if ($percent === null || $percent < 1 || $percent > 100) {
                throw new InvalidPromotionAttribute('percent', 'a whole number from 1 to 100');
            }

            if ($capMinor !== null && $capMinor < 1) {
                throw new InvalidPromotionAttribute('cap', 'more than 0, or none');
            }

            $amountMinor = null;
        } else {
            if ($amountMinor === null || $amountMinor < 1) {
                throw new InvalidPromotionAttribute('amount', 'more than 0');
            }

            [$percent, $capMinor] = [null, null];
        }

        if ($minimumMinor !== null && $minimumMinor < 1) {
            throw new InvalidPromotionAttribute('minimum', 'more than 0, or none');
        }

        // In UTC to the second, as they are stored: compared and shown as saved, whatever offset the
        // screen sent.
        $startsAt = self::moment($startsAt);
        $endsAt = $endsAt === null ? null : self::moment($endsAt);

        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidPromotionAttribute('dates', 'the end after the start');
        }

        foreach ([$maxUsesPerCustomer, $maxUsesTotal] as $most) {
            if ($most !== null && ($most < 1 || $most > self::MOST_USES)) {
                throw new InvalidPromotionAttribute('max_uses', 'from 1 to '.self::MOST_USES.', or none');
            }
        }

        if (! $forRetail && ! $forWholesale) {
            throw new InvalidPromotionAttribute('sale_modes', 'retail, wholesale or both');
        }

        if (! $forPublic && ! $forCompany) {
            throw new InvalidPromotionAttribute('buyers', 'everyone, companies or both');
        }

        // Only a company buys wholesale lines (handoff §6, amended 2026-10-07): a coupon for the public
        // only that covers wholesale lines only could never apply (§1.1).
        if (! $forCompany && ! $forRetail) {
            throw new InvalidPromotionAttribute('buyers', 'wholesale lines are bought by companies only');
        }

        return new self(
            self::name($nameAr),
            self::name($nameEn),
            CouponEligibility::tryFrom($eligibility) ?? throw new InvalidPromotionAttribute('eligibility', 'PUBLIC or ASSIGNED'),
            $kind,
            $percent,
            $capMinor,
            $amountMinor,
            $currencyCode,
            $minimumMinor,
            $startsAt,
            $endsAt,
            $maxUsesPerCustomer,
            $maxUsesTotal,
            $allowWithPoints,
            $onReducedLines,
            $forRetail,
            $forWholesale,
            $forPublic,
            $forCompany,
            self::rules($rules),
        );
    }

    /**
     * What staff see changed, attribute by attribute (the audit log).
     *
     * @return array<string, bool|int|string|list<string>|null>
     */
    public function snapshot(): array
    {
        $rules = array_map(static fn (CouponRule $rule): string => "{$rule->kind->value} {$rule->target->value} {$rule->targetId}", $this->rules);
        sort($rules);

        return [
            'name_ar' => $this->nameAr,
            'name_en' => $this->nameEn,
            'eligibility' => $this->eligibility->value,
            'discount_kind' => $this->discountKind->value,
            'percent' => $this->percent,
            'cap_minor' => $this->capMinor,
            'amount_minor' => $this->amountMinor,
            'minimum_minor' => $this->minimumMinor,
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
            'max_uses_per_customer' => $this->maxUsesPerCustomer,
            'max_uses_total' => $this->maxUsesTotal,
            'allow_with_points' => $this->allowWithPoints,
            'on_reduced_lines' => $this->onReducedLines,
            'for_retail' => $this->forRetail,
            'for_wholesale' => $this->forWholesale,
            'for_public' => $this->forPublic,
            'for_company' => $this->forCompany,
            'rules' => $rules,
        ];
    }

    /**
     * A coupon's terms as stored, read back unchecked: what was saved is what it is, whatever a rule
     * says today — so an old coupon can still be changed or deleted.
     *
     * @param  list<CouponRule>  $rules
     */
    public static function stored(
        string $nameAr, string $nameEn, CouponEligibility $eligibility, DiscountKind $discountKind, ?int $percent,
        ?int $capMinor, ?int $amountMinor, string $currencyCode, ?int $minimumMinor, DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt, ?int $maxUsesPerCustomer, ?int $maxUsesTotal, bool $allowWithPoints,
        bool $onReducedLines, bool $forRetail, bool $forWholesale, bool $forPublic, bool $forCompany, array $rules,
    ): self {
        return new self(
            $nameAr, $nameEn, $eligibility, $discountKind, $percent, $capMinor, $amountMinor, $currencyCode, $minimumMinor,
            self::moment($startsAt), $endsAt === null ? null : self::moment($endsAt), $maxUsesPerCustomer, $maxUsesTotal,
            $allowWithPoints, $onReducedLines, $forRetail, $forWholesale, $forPublic, $forCompany, $rules,
        );
    }

    private static function moment(DateTimeImmutable $at): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$at->getTimestamp());
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

    /**
     * @param  list<array{kind: string, target: string, id: string}>  $rules
     * @return list<CouponRule>
     */
    private static function rules(array $rules): array
    {
        $kept = [];

        foreach ($rules as $rule) {
            $kind = RuleKind::tryFrom($rule['kind']) ?? throw new InvalidPromotionAttribute('rules', 'INCLUDE or EXCLUDE');
            $target = RuleTarget::tryFrom($rule['target']) ?? throw new InvalidPromotionAttribute('rules', 'a product, a category or a brand');
            $id = strtolower(trim($rule['id']));

            if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
                throw new InvalidPromotionAttribute('rules', 'not an id');
            }

            // The same rule twice is one rule.
            $kept["{$kind->value} {$target->value} {$id}"] = new CouponRule($kind, $target, $id);
        }

        if (count($kept) > self::MOST_RULES) {
            throw new InvalidPromotionAttribute('rules', 'at most '.self::MOST_RULES);
        }

        return array_values($kept);
    }
}
