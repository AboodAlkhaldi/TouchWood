import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';
import type {
    CompanyTypeOptionData,
    CompanyValuesData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';

/*
| Small pieces the company page is built from (b2b.md §4.5): its cards, its status box, and how a
| time, a type or a document type is written in the page's language.
*/

export type Tone = 'plain' | 'good' | 'warn' | 'bad';

const TONES: Record<Tone, string> = {
    plain: 'border-line bg-surface',
    good: 'border-good/40 bg-good-soft',
    warn: 'border-warn/40 bg-warn-soft',
    bad: 'border-bad/40 bg-bad-soft',
};

const DOTS: Record<Tone, string> = {
    plain: 'bg-ink-muted',
    good: 'bg-good',
    warn: 'bg-warn',
    bad: 'bg-bad',
};

export function Card({ title, hint, children, test }: { title?: string; hint?: string; children: ReactNode; test?: string }) {
    return (
        <section data-test={test} className="grid gap-4 rounded-lg border border-line bg-surface p-5 shadow-card">
            {title ? (
                <header className="grid gap-0.5">
                    <h2 className="text-base font-semibold text-ink">{title}</h2>
                    {hint ? <p className="text-xs text-ink-muted">{hint}</p> : null}
                </header>
            ) : null}
            {children}
        </section>
    );
}

/** The design's status box: a dot, a title, and what it means now. */
export function StatusBox({ tone, title, children }: { tone: Tone; title: string; children?: ReactNode }) {
    return (
        <div data-test="status-box" data-tone={tone} className={['grid gap-1 rounded-lg border p-4', TONES[tone]].join(' ')}>
            <p className="flex items-center gap-2 text-sm font-semibold text-ink">
                <span aria-hidden className={['size-2 rounded-full', DOTS[tone]].join(' ')} />
                {title}
            </p>
            {children ? <div className="grid gap-2 text-sm text-ink">{children}</div> : null}
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

/** One label and its value, read-only. */
export function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-3">
            <dt className="text-xs text-ink-muted">{label}</dt>
            <dd className="text-sm break-words whitespace-pre-line text-ink">{children}</dd>
        </div>
    );
}
