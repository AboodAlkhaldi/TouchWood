import { figure, intlLocale } from '@/lib/digits';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { BandData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';

/*
| Pricing's boxes checked as they are typed (pricing.md §4.3.1 "Money and numbers", §4.3.3 P4) - pure
| functions, no React, so tests/Frontend/pricing-checks.test.ts runs them: a price or an amount in the
| store's decimals, a percentage from 0.01 to 99.99, a size's wholesale bands by the rules the server keeps.
| They help; the server decides (HANDOFF §2.4).
*/

export type Locale = 'ar' | 'en';
export type Translate = (key: string, values?: Record<string, string | number>) => string;

/** A count's words in the page's plural form ("3 bands"): a key holding zero, one, two, few, many, other. */
export function plural(t: Translate, locale: Locale, key: string, count: number, values: Record<string, string | number> = {}): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count), ...values });
}

/**
 * A left-to-right value - an amount with its letters, "−10%" - inside a translated sentence: kept in its own
 * order whatever the sentence's direction (U+2066 … U+2069; lib/bidi.ts explains the isolates).
 */
export function ltr(value: string): string {
    return `\u2066${value}\u2069`;
}

const NUMBER = /^\d+(\.\d+)?$/;

/** A price written as a person types it, in the store's decimals: 14950, 2 → "149.50". */
export function typedOf(minor: number, decimals: number): string {
    if (decimals <= 0) {
        return String(minor);
    }

    const digits = String(Math.abs(minor)).padStart(decimals + 1, '0');

    return `${minor < 0 ? '-' : ''}${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}

/** A typed price in the currency's smallest unit, or null when it is not one in these decimals. */
export function minorOf(text: string, decimals: number): number | null {
    const value = text.trim();

    if (!NUMBER.test(value)) {
        return null;
    }

    const [whole = '0', fraction = ''] = value.split('.');

    if (fraction.length > decimals) {
        return null;
    }

    return Number(whole) * 10 ** decimals + Number(fraction.padEnd(decimals, '0') || '0');
}

/** The example a price's check names, in the store's decimals: "149.50", "149.500", "149". */
export function exampleIn(decimals: number): string {
    return decimals <= 0 ? '149' : `149.${'50'.padEnd(decimals, '0').slice(0, decimals)}`;
}

/**
 * What is wrong with a typed price or amount, in Pricing's words (§4.3.1 "Money and numbers"), or null.
 *
 * @param noun  "price" for a price box, "amount" for a discount's amount
 */
export function priceProblem(text: string, decimals: number, t: Translate, locale: Locale, noun: 'price' | 'amount' = 'price'): string | null {
    const value = text.trim();
    const words = noun === 'price' ? 'pricing::admin.check.price' : 'pricing::admin_discounts.check.amount';

    if (!NUMBER.test(value)) {
        return t(`${words}_number`, { example: exampleIn(decimals) });
    }

    const places = value.includes('.') ? value.length - value.indexOf('.') - 1 : 0;

    if (places > decimals) {
        return decimals === 0 ? t(`${words}_whole`) : plural(t, locale, `${words}_decimals`, decimals);
    }

    return Number(value) > 0 ? null : t(`${words}_positive`);
}

/** What is wrong with a typed percentage: from 0.01 to 99.99, at most 2 decimals. */
export function percentProblem(text: string, t: Translate): string | null {
    const value = text.trim();

    if (!NUMBER.test(value)) {
        return t('pricing::admin.check.percent_range');
    }

    if (value.includes('.') && value.length - value.indexOf('.') - 1 > 2) {
        return t('pricing::admin.check.percent_decimals');
    }

    const number = Number(value);

    return number >= 0.01 && number <= 99.99 ? null : t('pricing::admin.check.percent_range');
}

const WHOLE = /^\d+$/;

export type BandsFacts = {
    retail: MoneyData | null;
    minimum: number | null;
    maximum: number | null;
    bands: BandData[];
    minimumChanged: boolean;
};

/** A band as typed: its quantity and its price. */
export type Row = { from: string; price: string };

/** What is wrong with each row as typed, by the rules of §1.4 and Q12 - the first problem of each. */
export function bandProblems(rows: Row[], facts: BandsFacts, decimals: number, t: Translate, locale: Locale): { from?: string; price?: string }[] {
    return rows.map((row, index) => {
        const before = index > 0 ? rows[index - 1] : undefined;
        const problems: { from?: string; price?: string } = {};
        const from = row.from.trim();

        if (!WHOLE.test(from) || Number(from) < 1) {
            problems.from = t('pricing::admin.bands_dialog.check.from_whole');
        } else if (index === 0 && facts.minimum !== null && Number(from) !== facts.minimum) {
            problems.from = t('pricing::admin.bands_dialog.check.first', { minimum: figure(locale, facts.minimum) });
        } else if (before !== undefined && WHOLE.test(before.from.trim()) && Number(from) <= Number(before.from.trim())) {
            problems.from = t('pricing::admin.bands_dialog.check.from_order');
        } else if (facts.maximum !== null && Number(from) > facts.maximum) {
            problems.from = t('pricing::admin.bands_dialog.check.from_max', { maximum: figure(locale, facts.maximum) });
        }

        const own = priceProblem(row.price, decimals, t, locale);
        const minor = minorOf(row.price, decimals);
        const previous = before === undefined ? null : minorOf(before.price, decimals);

        if (own !== null) {
            problems.price = own;
        } else if (index === 0 && facts.retail !== null && minor !== null && minor >= facts.retail.minor) {
            problems.price = t('pricing::admin.bands_dialog.check.first_price', { retail: ltr(facts.retail.text) });
        } else if (previous !== null && minor !== null && minor >= previous) {
            problems.price = t('pricing::admin.bands_dialog.check.price_order');
        }

        return problems;
    });
}
