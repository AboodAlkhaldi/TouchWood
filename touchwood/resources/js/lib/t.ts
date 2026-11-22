/*
| The one way a screen gets its words (frontend.md §1.5).
|
| There are no frontend translation files. The text lives in Laravel's lang files, where the
| backend's text already is, and each page is sent only the files it named, already in the page's
| language. So t() is a lookup in what arrived, not a translation engine.
*/

import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/**
 * Replaces :name placeholders the way Laravel does, so one key reads the same on both sides.
 *
 * In one pass, the longest placeholder first, as Laravel's strtr() does: replaced one after another,
 * ":to" ate the start of ":total" and the pager read "1–5 of 5tal" (B2B step 7, the first screen to
 * use the pager).
 */
function fill(line: string, values: Record<string, string | number>): string {
    const keys = Object.keys(values).sort((a, b) => b.length - a.length);

    if (keys.length === 0) {
        return line;
    }

    const pattern = new RegExp(keys.map((key) => `:${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`).join('|'), 'g');

    return line.replace(pattern, (found) => String(values[found.slice(1)]));
}

/**
 * The words behind a key, e.g. t('access::auth.sign_in').
 *
 * A key the page was not sent comes back as the key itself. That is deliberate: a missing word must
 * be visible on the screen rather than rendering as an empty space nobody notices - and a test
 * (tests/Architecture/TranslationKeysTest) fails the build for a key missing in either language, so
 * this is the last resort, not the plan.
 */
export function useTranslator(): (key: string, values?: Record<string, string | number>) => string {
    const { translations } = usePage<SharedProps>().props;

    return (key, values = {}) => fill(translations[key] ?? key, values);
}

/**
 * The same lookup where there is no component to hold a hook - inside a table column definition,
 * for example. It takes the translations it was given rather than reaching for the page.
 */
export function translate(
    translations: Record<string, string>,
    key: string,
    values: Record<string, string | number> = {},
): string {
    return fill(translations[key] ?? key, values);
}
