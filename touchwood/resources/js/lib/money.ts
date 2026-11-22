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

/**
 * An amount of minor units written as the server writes `template` (Shared Money::format): the same decimals and sign or
 * letters after it, the whole part grouped with commas. A BigInt, so a large amount keeps its last digits.
 */
export function moneyAs(template: { text: string }, minor: bigint): { text: string; minor: number } {
    const match = /^(-?[\d,]+)(?:\.(\d+))?(.*)$/.exec(template.text);
    const decimals = match?.[2]?.length ?? 0;
    const digits = (minor < 0n ? -minor : minor).toString().padStart(decimals + 1, '0');
    const whole = digits.slice(0, digits.length - decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = decimals > 0 ? `.${digits.slice(-decimals)}` : '';

    return { text: `${minor < 0n ? '-' : ''}${whole}${fraction}${match?.[3] ?? ''}`, minor: Number(minor) };
}

/** A server-written amount times a count: the buy box's total (shop-design.md §6.3). */
export function moneyTimes(value: { text: string; minor: number }, count: number): { text: string; minor: number } {
    return moneyAs(value, BigInt(Math.round(value.minor)) * BigInt(Math.max(0, Math.trunc(count))));
}
