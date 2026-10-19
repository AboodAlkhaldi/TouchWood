import { intlLocale } from '@/lib/digits';

/*
| An amount as every screen writes it (loyalty.md §4.1; pricing.md §4.3): the server sends its decimal
| text with the currency's own decimals ("1234.50") and the currency's code, never a float; this groups
| it as the page's language groups numbers, in Latin digits (frontend.md §1.8), and puts the code
| after it - "1,234.50 SAR". The one formatter every screen showing money uses.
|
| The digits are never turned into a JavaScript number: a large amount would lose its last digits,
| so only the whole part is grouped, as text.
*/

export type Money = { amount: string; currency: string };

/** "1,234.50 SAR" (with the page's grouping); an amount that is not decimal text as it came. */
export function formatMoney(money: Money, locale: string): string {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(money.amount);

    if (match === null) {
        return `${money.amount} ${money.currency}`;
    }

    const [, sign = '', whole = '', fraction] = match;
    const parts = new Intl.NumberFormat(intlLocale(locale)).formatToParts(12345.6);
    const group = parts.find((part) => part.type === 'group')?.value ?? ',';
    const decimal = parts.find((part) => part.type === 'decimal')?.value ?? '.';
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, group);

    return `${sign}${grouped}${fraction === undefined ? '' : `${decimal}${fraction}`} ${money.currency}`;
}
