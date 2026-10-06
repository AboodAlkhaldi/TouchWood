import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Note, type NoteVariant } from '@/components/Note';
import { Card as ShadcnCard, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { SharedProps } from '@/types/page';
import type { CompanyTypeOptionData, CompanyValuesData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';

/*
| Small pieces the company page is built from (b2b.md §4.5), on shadcn's parts with Geist's rules
| (frontend.md §1.11): its cards, its status box, and how a type, a code or a written value is put
| on the page in its language. A moment is Time's, in the home store's zone (HANDOFF §4).
|
| A card is shadcn's Card in Geist's base material. The status box is Geist's Note, its colour chosen
| by what the status means - neutral before anything is sent, amber while it waits, green once
| approved, red when refused or suspended - its title the Note's label and its content one sentence
| (Geist's Note: one Note per concept). Read-only values go in Geist's Description, where a value
| that is not there is an em dash; `figureOr` and `writtenOr` hand it nothing rather than an empty
| element, so the dash shows.
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
        <ShadcnCard data-test={test} className="material-base gap-0 border-0 py-0">
            {title ? (
                <CardHeader className="gap-1 px-5 pt-5 pb-4">
                    <CardTitle className="text-heading-16 text-ink">
                        <h2>{title}</h2>
                    </CardTitle>
                    {hint ? <CardDescription className="text-copy-13 text-ink-muted">{hint}</CardDescription> : null}
                </CardHeader>
            ) : null}
            <CardContent className={title ? 'grid gap-4 px-5 pb-5' : 'grid gap-4 p-5'}>{children}</CardContent>
        </ShadcnCard>
    );
}

/** The design's status box, as Geist's Note: the status as its label, then what it means now. */
export function StatusBox({ tone, title, children }: { tone: Tone; title: string; children?: ReactNode }) {
    return (
        <div data-test="status-box" data-tone={tone}>
            <Note variant={NOTE[tone]} label={title}>
                {children}
            </Note>
        </div>
    );
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

/** Stands in for a moment inside a translated sentence, so each language keeps its own order. */
export const MARK = '⁣';

/** A sentence with a moment inside it ("Sent 2h ago"), the moment drawn by Time. */
export function Phrase({ text, moment }: { text: string; moment: ReactNode }) {
    const [before, after] = text.split(MARK);

    return (
        <>
            {before}
            {moment}
            {after}
        </>
    );
}

/** Codes and numbers read left to right on an Arabic page too (frontend.md §1.8). */
export function Figure({ children }: { children: ReactNode }) {
    return (
        <bdi className="tw-figure" dir="ltr">
            {children}
        </bdi>
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
