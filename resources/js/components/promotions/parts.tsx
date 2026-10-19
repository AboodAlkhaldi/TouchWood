import { usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { isolate } from '@/lib/bidi';
import { figure, intlLocale } from '@/lib/digits';
import { formatMoney, type Money } from '@/lib/money';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { SharedProps } from '@/types/page';

/*
| The parts Promotions' screens share (promotions.md §4.1): a coupon's state badge, what it takes off in
| words, an amount, and the em dash for nothing. Not a page: each counts inside the pages that import it.
*/

export function useLocale(): string {
    return usePage<SharedProps>().props.locale;
}

/** A count's words in the page's plural form: a key holding zero, one, two, few, many, other (as Inventory's). */
export function plural(t: ReturnType<typeof useTranslator>, locale: string, key: string, count: number, values: Record<string, string | number> = {}): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count), ...values });
}

/** A value that is not there: Geist's em dash. */
export function Unknown() {
    return <span className="text-ink-subtle">—</span>;
}

/** An amount with its currency (lib/money), isolated from the text around it. */
export function Amount({ value }: { value: Money }) {
    const locale = useLocale();

    return (
        <bdi dir="ltr" className="tw-figure">
            {formatMoney(value, locale)}
        </bdi>
    );
}

/** A coupon's state as one Geist badge (§4): Active green, Scheduled blue, Ended and Off gray. */
export function CouponStateBadge({ state }: { state: string }) {
    const t = useTranslator();
    const look = state === 'ACTIVE' ? 'green-subtle' : state === 'SCHEDULED' ? 'blue-subtle' : 'gray-subtle';

    return (
        <Badge className={tone(look)} data-test="coupon-state">
            {t(`promotions::admin_coupons.state.${state}`)}
        </Badge>
    );
}

/** What a coupon takes off, in words: "15%, up to 100.00 SAR", "25.00 SAR off". */
export function useDiscountWords(): (kind: string, percent: number | null, amount: Money | null, cap: Money | null) => string {
    const t = useTranslator();
    const locale = useLocale();

    return (kind, percent, amount, cap) => {
        if (kind === 'FIXED' && amount !== null) {
            return t('promotions::discounts.fixed', { amount: isolate(formatMoney(amount, locale)) });
        }

        const share = `${figure(locale, percent ?? 0)}%`;

        return cap === null
            ? t('promotions::discounts.percent', { percent: isolate(share) })
            : t('promotions::discounts.percent_cap', { percent: isolate(share), cap: isolate(formatMoney(cap, locale)) });
    };
}
