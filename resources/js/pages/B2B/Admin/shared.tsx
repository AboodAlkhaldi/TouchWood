import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';

/*
| What B2B's staff screens share (b2b.md §4.6): the page's language, a type's name in it, and a
| count in its digits. A status's colour is in ../status.ts, shared with the shop; a moment is
| Time's; a refusal that names no field is FormError's.
*/

export type Locale = 'ar' | 'en';

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar : en) ?? '';
}

/** A whole number in the page's digits (frontend.md §1.8), shared with the panel's frame. */
export { figure } from '@/lib/digits';
