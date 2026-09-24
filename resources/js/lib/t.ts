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
 */
function fill(line: string, values: Record<string, string | number>): string {
    return Object.entries(values).reduce(
        (text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
        line,
    );
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
