<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Modules\Promotions\Application\Command\CouponFields;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\MoneyException;

/**
 * The coupon form as posted (promotions.md §4.1 M2), read into `CouponFields`: amounts as the store
 * writes them ("149.50", the currency's decimals), moments as ISO 8601 with the store's offset (sent by
 * `lib/moments`; none for "now" and for "no end"), whole numbers as digits. A value that cannot be read
 * is said beside its own box (`CouponFormProblem`), before the use case sees anything; the use case
 * checks the rest (`CouponTerms`) and answers with its own refusal.
 */
final readonly class CouponForm
{
    /** The form's own boxes, where a refusal naming one is said. */
    public const array FIELDS = ['code', 'name', 'name_ar', 'name_en', 'eligibility', 'discount_kind', 'percent', 'cap', 'amount', 'minimum', 'starts_at', 'ends_at', 'max_uses', 'max_uses_per_customer', 'max_uses_total', 'sale_modes', 'buyers', 'rules', 'dates'];

    /**
     * @throws CouponFormProblem
     */
    public static function fields(Request $request, string $currency, int $exponent): CouponFields
    {
        $kind = strtoupper(self::text($request, 'discount_kind') ?? '');

        return new CouponFields(
            self::text($request, 'name_ar') ?? '',
            self::text($request, 'name_en') ?? '',
            strtoupper(self::text($request, 'eligibility') ?? ''),
            $kind,
            $kind === 'PERCENT' ? self::whole($request, 'percent') : null,
            $kind === 'PERCENT' ? self::money($request, 'cap', $currency, $exponent) : null,
            $kind === 'FIXED' ? self::money($request, 'amount', $currency, $exponent) : null,
            self::money($request, 'minimum', $currency, $exponent),
            self::moment($request, 'starts_at') ?? new DateTimeImmutable,
            self::moment($request, 'ends_at'),
            self::whole($request, 'max_uses_per_customer'),
            self::whole($request, 'max_uses_total'),
            $request->boolean('allow_with_points'),
            $request->boolean('on_reduced_lines'),
            $request->boolean('for_retail'),
            $request->boolean('for_wholesale'),
            $request->boolean('for_public'),
            $request->boolean('for_company'),
            self::rules($request),
        );
    }

    public static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * An amount in the smallest unit, or none when the box is empty.
     *
     * @throws CouponFormProblem
     */
    public static function money(Request $request, string $key, string $currency, int $exponent): ?int
    {
        $typed = self::text($request, $key);

        if ($typed === null) {
            return null;
        }

        try {
            return Money::fromDecimal(LatinDigits::of($typed), $currency, $exponent)->minorUnits;
        } catch (MoneyException) {
            throw new CouponFormProblem($key, $exponent === 0 ? 'whole' : 'amount', ['decimals' => $exponent]);
        }
    }

    /**
     * @throws CouponFormProblem
     */
    private static function whole(Request $request, string $key): ?int
    {
        $typed = self::text($request, $key);

        if ($typed === null) {
            return null;
        }

        $digits = LatinDigits::of($typed);

        if (! ctype_digit($digits) || strlen($digits) > 10) {
            throw new CouponFormProblem($key, 'whole');
        }

        return (int) $digits;
    }

    /**
     * @throws CouponFormProblem
     */
    public static function moment(Request $request, string $key): ?DateTimeImmutable
    {
        $typed = self::text($request, $key);

        if ($typed === null) {
            return null;
        }

        // As lib/moments sends a typed one, or as the page was given a saved one (milliseconds kept);
        // a day that rolls over - 30 February read as 2 March - is refused, never moved.
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP'] as $format) {
            $moment = DateTimeImmutable::createFromFormat('!'.$format, $typed);
            $problems = DateTimeImmutable::getLastErrors();

            if ($moment !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $moment;
            }
        }

        throw new CouponFormProblem($key, 'moment');
    }

    /**
     * The Includes and Excludes as sent: each a kind, a target and an id; anything else is left out
     * here and refused by the use case's own rules when it is not a valid entry.
     *
     * @return list<array{kind: string, target: string, id: string}>
     */
    private static function rules(Request $request): array
    {
        $sent = $request->input('rules');
        $rules = [];

        foreach (is_array($sent) ? $sent : [] as $rule) {
            if (is_array($rule) && is_string($rule['kind'] ?? null) && is_string($rule['target'] ?? null) && is_string($rule['id'] ?? null)) {
                $rules[] = ['kind' => strtoupper($rule['kind']), 'target' => strtolower($rule['target']), 'id' => $rule['id']];
            }
        }

        return $rules;
    }
}
