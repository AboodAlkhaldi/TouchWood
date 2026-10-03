import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Note, type NoteVariant } from '@/components/geist';
import type { SharedProps } from '@/types/page';
import type {
    CompanyTypeOptionData,
    CompanyValuesData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';

/*
| Small pieces the company page is built from (b2b.md §4.5), on Geist's parts (frontend.md 1.10):
| its cards, its status box, and how a time, a type, a code or a written value is put on the page in
| its language.
|
| A card is Geist's base material. The status box is Geist's Note, its colour chosen by what the
| status means - neutral before anything is sent, amber while it waits, green once approved, red
| when refused or suspended - and its title the Note's label, so it reads "Under Review: …" as a
| Note does. Read-only values go in Geist's Description, where a value that is not there is an em
| dash; `figureOr` and `writtenOr` hand it nothing rather than an empty element, so the dash shows.
*/

export type Tone = 'plain' | 'good' | 'warn' | 'bad';

const NOTE: Record<Tone, NoteVariant> = {
    plain: 'secondary',
    good: 'success',
    warn: 'warning',
    bad: 'error',
};

export function Card({ title, hint, children, test }: { title?: string; hint?: string; children: ReactNode; test?: string }) {
    return (
        <section data-test={test} className="material-base grid gap-4 p-5">
            {title ? (
                <header className="grid gap-1">
                    <h2 className="text-heading-16 text-ink">{title}</h2>
                    {hint ? <p className="text-copy-13 text-ink-muted">{hint}</p> : null}
                </header>
            ) : null}
            {children}
        </section>
    );
}

/**
 * The design's status box, as Geist's Note: the status as its label, then what it means now. The
 * first sentence runs on from the label; anything after it is a paragraph of its own.
 */
export function StatusBox({ tone, title, children }: { tone: Tone; title: string; children?: ReactNode }) {
    return (
        <div data-test="status-box" data-tone={tone}>
            <Note variant={NOTE[tone]} label={title}>
                {children}
            </Note>
        </div>
    );
}

/**
 * A time as the server wrote it — already in the home store's clock (HANDOFF §4) — to the minute.
 * It is never converted again here: the browser's own clock is not the store's.
 */
export function when(at: string | null): string {
    return at === null ? '' : at.slice(0, 16).replace('T', ' ');
}

export function useLocale(): 'ar' | 'en' {
    return usePage<SharedProps>().props.locale;
}

/** A type's name in the page's language. */
export function nameOf(option: Pick<CompanyTypeOptionData, 'nameAr' | 'nameEn'>, locale: 'ar' | 'en'): string {
    return locale === 'ar' ? option.nameAr : option.nameEn;
}

/** A company's type as the company sees it: a listed type's name, or its own words for "Other". */
export function typeOf(values: CompanyValuesData, locale: 'ar' | 'en'): string {
    if (values.companyTypeOther !== null) {
        return values.companyTypeOther;
    }

    return (locale === 'ar' ? values.companyTypeNameAr : values.companyTypeNameEn) ?? '';
}

/** Figures, codes and numbers read left to right on an Arabic page too (frontend.md §1.8). */
export function Figure({ children }: { children: ReactNode }) {
    return (
        <span className="tw-figure" dir="ltr">
            {children}
        </span>
    );
}

/** A code or number for a Description, or nothing - its em dash - when there is none. */
export function figureOr(value: string | null): ReactNode {
    return value === null || value === '' ? null : <Figure>{value}</Figure>;
}

/** Words as the company wrote them, line breaks kept, or nothing - the em dash - when there are none. */
export function writtenOr(value: string | null): ReactNode {
    return value === null || value === '' ? null : <span className="break-words whitespace-pre-line">{value}</span>;
}
