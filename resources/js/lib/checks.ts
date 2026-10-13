/*
| Every box checks itself as it is typed (frontend.md §1.7; the owner, 2026-10-09: "we dont wanna
| wait until request returns red to a numeric value that was entered character, it must be from the
| moment typed"; catalog.md amendment 16(f)).
|
| A box declares its rules once - required, a number and its range, a length, digits or letters only,
| an email's or a phone's shape, a module's own format - and this file says what is wrong with a
| value, in the words of `lang/{ar,en}/ui.php` ("check"), naming the box and the rule (§1.10's
| "Validation" row: "Weight takes numbers only."). The shared fields (components/Fields.tsx, through
| lib/use-checks.ts) say it under the box; nothing here knows a screen.
|
| The rules are the server's own, copied from the domain each box is sent to (the source is named
| where each form declares them). The page's check saves a round trip, never replaces one: the
| server still checks everything, and where the two could differ the page's rule is the looser one,
| so a value the server takes is never stopped here - with one kind of exception, chosen on purpose:
| a number box refuses letters even where the server would read "abc" as 0 (Platform's positions).
*/

import { intlLocale, toLatinDigits } from '@/lib/digits';

export type Rules = {
    /** Said once the box was typed in or left, or Save pressed - never on a fresh form. */
    required?: boolean;
    /**
     * A number: digits, a minus in front, and a point with at most `decimals` places after it
     * (none: a whole number). Its range, when given, counts the ends in.
     */
    number?: { decimals?: number; min?: number; max?: number };
    /** Only the digits 0-9: a code. Its length then counts digits. */
    digits?: boolean;
    /** Only the Latin letters A-Z, either case: a currency's or a store's code. */
    letters?: boolean;
    /**
     * How many characters, counted as the server counts them: after the ends are trimmed, each
     * character once however JavaScript stores it - or by `of`, where the server counts something
     * else (the text a description's marks leave, catalog.md §1.12).
     */
    length?: { min?: number; max?: number; of?: (text: string) => number };
    /** An email address's shape: something, an @, something - no spaces. */
    email?: boolean;
    /**
     * A phone number as Access keeps one (PhoneNumber): with its country code - a + or 00 in front,
     * then 7 to 15 digits, the first not 0 - spaces, dashes, dots and brackets between them ignored.
     */
    phone?: boolean;
    /** Spaces at the ends count, as a password's do: nothing is trimmed from one. */
    keepSpaces?: boolean;
    /** A module's own shape - an address's letters, a colour code - and its sentence's key, taking :field. */
    format?: { pattern: RegExp; key: string };
};

/** What is wrong: a rule of `ui.check` (or the module's own key) and the figures its sentence names. */
export type Problem = { rule: string; values: Record<string, number>; key?: string };

type Translate = (key: string, values?: Record<string, string | number>) => string;

/** A number as a person types it: a minus, digits, one point and more digits. */
const NUMBER = /^-?\d+(\.\d+)?$/;
const DIGITS = /^\d+$/;
const LETTERS = /^[A-Za-z]+$/;
// Loose on purpose: the server's own rule decides an address it does not take.
const EMAIL = /^[^\s@]+@[^\s@]+$/;
// Access's PhoneNumber::E164, read after the same cleaning.
const PHONE = /^\+[1-9]\d{6,14}$/;

/** A phone number cleaned as Access's PhoneNumber cleans it, before its shape is read. */
function phoneOf(text: string): string {
    const digits = toLatinDigits(text).replace(/[\s\-.()]/g, '');

    return digits.startsWith('00') ? `+${digits.slice(2)}` : digits;
}

/**
 * What is wrong with a value, or null when the box would send it. An empty box is wrong only when
 * it is required: nothing else is said about a value that is not there.
 */
export function problemOf(value: string, rules: Rules): Problem | null {
    const text = rules.keepSpaces === true ? value : value.trim();

    if (text === '') {
        return rules.required === true ? { rule: 'required', values: {} } : null;
    }

    if (rules.number !== undefined) {
        return numberProblem(text, rules.number);
    }

    if (rules.digits === true && !DIGITS.test(text)) {
        return { rule: 'digits', values: {} };
    }

    if (rules.letters === true && !LETTERS.test(text)) {
        return { rule: 'letters', values: {} };
    }

    const length = lengthProblem(text, rules.length, rules.digits === true);

    if (length !== null) {
        return length;
    }

    if (rules.email === true && !EMAIL.test(text)) {
        return { rule: 'email', values: {} };
    }

    if (rules.phone === true && !PHONE.test(phoneOf(text))) {
        return { rule: 'phone', values: {} };
    }

    if (rules.format !== undefined && !rules.format.pattern.test(text)) {
        return { rule: 'format', values: {}, key: rules.format.key };
    }

    return null;
}

function numberProblem(text: string, rule: NonNullable<Rules['number']>): Problem | null {
    if (!NUMBER.test(text)) {
        return { rule: 'number', values: {} };
    }

    const decimals = rule.decimals ?? 0;
    const places = text.includes('.') ? text.length - text.indexOf('.') - 1 : 0;

    if (places > decimals) {
        return decimals === 0 ? { rule: 'whole', values: {} } : { rule: decimals === 1 ? 'decimals_one' : 'decimals', values: { count: decimals } };
    }

    const number = Number(text);
    const { min, max } = rule;

    if ((min !== undefined && number < min) || (max !== undefined && number > max)) {
        if (min !== undefined && max !== undefined) {
            return { rule: 'range', values: { min, max } };
        }

        return min !== undefined ? { rule: 'at_least', values: { min } } : { rule: 'at_most', values: { max: max ?? 0 } };
    }

    return null;
}

function lengthProblem(text: string, rule: Rules['length'], digits: boolean): Problem | null {
    if (rule === undefined) {
        return null;
    }

    // Characters, not UTF-16 units: an emoji is one, as PHP's mb_strlen counts it.
    const length = rule.of === undefined ? [...text].length : rule.of(text);

    if (rule.min !== undefined && length < rule.min) {
        return { rule: digits ? 'min_digits' : 'min_length', values: { min: rule.min } };
    }

    if (rule.max !== undefined && length > rule.max) {
        if (rule.max === 1) {
            return { rule: 'max_length_one', values: {} };
        }

        return { rule: digits ? 'max_digits' : 'max_length', values: { max: rule.max } };
    }

    return null;
}

/**
 * The problem as a sentence naming the box (Geist: "Email address is required."), its figures in
 * Latin digits grouped as the page's language groups them (frontend.md §1.8).
 */
export function sentenceOf(problem: Problem, subject: string, t: Translate, locale: string): string {
    const format = new Intl.NumberFormat(intlLocale(locale));
    const values: Record<string, string> = { field: subject };

    for (const [name, figure] of Object.entries(problem.values)) {
        values[name] = format.format(figure);
    }

    return t(problem.key ?? `ui.check.${problem.rule}`, values);
}

/**
 * A box's label as the subject of a sentence: in English, sentence case - every later word that is
 * only capitalised is lowered, an all-capital one (SMS, VAT) and a language's name kept; Arabic has
 * no case and is kept as it is.
 */
export function subjectOf(label: string, locale: string): string {
    if (locale === 'ar') {
        return label;
    }

    // A later word: after a space, or after a space and an opening bracket - "(Minutes)".
    return label.replace(/(?<=\S\s+\(?)\p{Lu}\p{Ll}+/gu, (word) => (KEPT.has(word) ? word : word.toLowerCase()));
}

// Proper names that stay capitalised inside an English sentence.
const KEPT = new Set(['Arabic', 'English']);
