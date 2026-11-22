import type { DiscountRowData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { ltr, percentOf, useLocale, useT, type Locale, type Translate } from './parts';

/** How much a discount takes off, as words: "10%" or "50.00 ر.س off" (pricing.md §4.3.3 P3 "Off"). */
export function offText(t: Translate, locale: Locale, discount: Pick<DiscountRowData, 'percentBasisPoints' | 'amountOff'>): string {
    if (discount.percentBasisPoints !== null) {
        return ltr(`${percentOf(locale, discount.percentBasisPoints)}%`);
    }

    return discount.amountOff === null ? '—' : t('pricing::admin.amount_off', { amount: ltr(discount.amountOff.text) });
}

/** The "Off" cell. */
export function DiscountOff({ discount }: { discount: Pick<DiscountRowData, 'percentBasisPoints' | 'amountOff'> }) {
    const t = useT();
    const locale = useLocale();

    return <span className="tw-figure whitespace-nowrap">{offText(t, locale, discount)}</span>;
}
