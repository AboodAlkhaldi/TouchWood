import { usePage } from '@inertiajs/react';
import { Note, type BadgeVariant } from '@/components/geist';
import type { SharedProps } from '@/types/page';

/*
| What B2B's staff screens share (b2b.md §4.6): how a time, a status and a type's name are shown, and
| where a refusal that names no field is said.
*/

export type Locale = 'ar' | 'en';

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/**
 * A time as the server wrote it — already in the company's home store's clock (HANDOFF §4) — to the
 * minute. Never converted again here: the browser's clock is not the store's.
 */
export function when(at: string | null): string {
    return at === null ? '' : at.slice(0, 16).replace('T', ' ');
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar : en) ?? '';
}

/** A whole number in the page's digits (frontend.md §1.8): Arabic-Indic on an Arabic page. */
export function figure(locale: Locale, value: number): string {
    return new Intl.NumberFormat(locale === 'ar' ? 'ar' : 'en').format(value);
}

/**
 * A company's status by meaning, never by colour alone - the word is always beside it (Geist's
 * badge rules): waiting is a warning, approved healthy, suspended an error, rejected neutral.
 */
export function companyStatusLook(status: string): BadgeVariant {
    switch (status) {
        case 'PENDING':
            return 'amber-subtle';
        case 'APPROVED':
            return 'green-subtle';
        case 'SUSPENDED':
            return 'red-subtle';
        default:
            return 'gray-subtle';
    }
}

export function applicationStateLook(state: string): BadgeVariant {
    switch (state) {
        case 'SUBMITTED':
            return 'blue-subtle';
        case 'APPROVED':
            return 'green-subtle';
        default:
            return 'gray-subtle';
    }
}

/**
 * A refusal that belongs to no single field, said where the person is looking (frontend.md §2.1):
 * the toast fades, this stays until the next answer.
 */
export function FormNote() {
    const { errors } = usePage<SharedProps>().props;
    const message = errors.form;

    if (message === undefined || message === '') {
        return null;
    }

    return (
        <Note variant="error" alert data-test="form-error">
            {message}
        </Note>
    );
}
