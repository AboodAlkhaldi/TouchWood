import { problemOf, type Rules } from '@/lib/checks';
import type { Locale } from './parts';

/*
| A web address as Slug::of takes one typed (catalog.md §1.1, §5.3): at most 200 characters
| (Slug::MAX), words joined by single hyphens - in English lower-case a-z and digits, in Arabic the
| Arabic letters (U+0621-U+063F, U+0641-U+064A, U+0671-U+06D3) and digits. Arabic-Indic digits are let
| through in both, as the server turns them into 0-9 before it reads the shape (CatalogText).
*/
export const SLUG_RULES = {
    en: { length: { max: 200 }, format: { pattern: /^[a-z0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+(-[a-z0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+)*$/u, key: 'catalog::admin.check.slug_en' } },
    ar: {
        length: { max: 200 },
        format: {
            pattern: /^[\u{0621}-\u{063F}\u{0641}-\u{064A}\u{0671}-\u{06D3}0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+(-[\u{0621}-\u{063F}\u{0641}-\u{064A}\u{0671}-\u{06D3}0-9\u{0660}-\u{0669}\u{06F0}-\u{06F9}]+)*$/u,
            key: 'catalog::admin.check.slug_ar',
        },
    },
} satisfies Record<Locale, Rules>;

/** Whether a typed address breaks its rule: its section is then never folded away out of sight. */
export function slugsWrong(ar: string, en: string): boolean {
    return problemOf(ar, SLUG_RULES.ar) !== null || problemOf(en, SLUG_RULES.en) !== null;
}
