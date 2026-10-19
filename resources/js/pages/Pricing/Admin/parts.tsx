import { createContext, useContext, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { formatDate } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { figure, intlLocale } from '@/lib/digits';
import { translate, useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { PricingFrameData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { ltr, plural, type Locale, type Translate } from './checks';

export { ltr, plural, type Locale, type Translate };
export { Guarded, MoreButton } from '@/components/RowMenu';
export { readJson, useDebounced, useLiveRead } from '@/lib/live-read';

/*
| What Pricing's screens share (pricing.md §4.3.1): their words - on Pricing's own pages from the page,
| on a product's Stores tab from the section's data (PricingWords) -, the frame above the rows (the store
| filter, Off, the discount ceiling, a wired store's Note), a price box's check as typed in the store's
| decimals, the marks every row can carry, and the ⋯ button. Every piece is shadcn's parts under Geist's
| rules (frontend.md §1.11); nothing here draws a component of its own making.
*/


/** Pricing's words when they do not arrive with the page: a product's Stores tab sends them in its data. */
export const PricingWords = createContext<Record<string, string> | null>(null);

/** The page's words, or the section's own where Pricing draws on another module's page. */
export function useT(): Translate {
    const page = useTranslator();
    const own = useContext(PricingWords);

    return own === null ? page : (key, values = {}) => (own[key] === undefined ? page(key, values) : translate(own, key, values));
}

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar || en : en || ar) ?? '';
}

/** The other language's name, under the page's (P1 "Product"). */
export function otherName(locale: Locale, ar: string | null, en: string | null): string | null {
    return locale === 'ar' ? en : ar;
}


/** A size's values joined - "60 cm · Oak" - or "One Size". */
export function sizeName(t: Translate, values: string[]): string {
    return values.length === 0 ? t('pricing::admin.one_size') : values.join(' · ');
}


/** A percentage kept in basis points, as a person reads it: 1250 → "12.5". */
export function percentOf(locale: Locale, basisPoints: number): string {
    return new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: 2 }).format(basisPoints / 100);
}

/** An offer's or a discount's depth: "−12.5%", or its price / amount (§4.3.1 "Signs and arrows"). */
export function Off({ percentBasisPoints, money }: { percentBasisPoints: number | null; money: MoneyData | null }) {
    const locale = useLocale();

    if (percentBasisPoints !== null) {
        return (
            <bdi dir="ltr" className="tw-figure">
                {`−${percentOf(locale, percentBasisPoints)}%`}
            </bdi>
        );
    }

    return money === null ? <>—</> : <Money value={money} />;
}

/** A date in a store's zone, as components/Time writes one ("30 Oct 2026"), for a moment inside a sentence. */
export function useDate(zone: string): (iso: string) => string {
    const locale = useLocale();

    return (iso) => formatDate(iso, zone, locale);
}

/** Another offer or discount in the same dates: "−10% · 60 cm, 80 cm, 1 Nov to 30 Nov". */
export function OverlapLine({ description, startsAt, endsAt, alwaysWins, zone }: { description: string; startsAt: string; endsAt: string | null; alwaysWins: boolean; zone: string }) {
    const t = useT();
    const date = useDate(zone);

    return (
        <span>
            {t('pricing::admin.note.overlap_line', { what: description, dates: t('pricing::admin.note.dates', { start: date(startsAt), end: endsAt === null ? t('pricing::admin.no_end') : date(endsAt) }) })}
            {alwaysWins ? (
                <>
                    {' '}
                    <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark>
                </>
            ) : null}
        </span>
    );
}

/** "from → to": Lucide's arrow, mirrored on Arabic pages, read out as "to". */
export function ToArrow() {
    const t = useT();

    return (
        <>
            <ArrowRight aria-hidden="true" className="inline size-3.5 rtl:rotate-180" />
            <span className="sr-only">{t('pricing::admin.to')}</span>
        </>
    );
}

/** A mark on a row, in words as well as colour (Geist's Badge). */
export function Mark({ tone: name, children, test }: { tone: Tone; children: ReactNode; test?: string }) {
    return (
        <Badge className={tone(name)} data-test={test}>
            {children}
        </Badge>
    );
}

const STATE_TONE: Record<string, Tone> = { SCHEDULED: 'blue-subtle', RUNNING: 'green-subtle', ENDED: 'gray-subtle' };

/** An offer's or a discount's state: Scheduled blue, Running green, Ended gray. */
export function StateBadge({ state }: { state: string }) {
    const t = useT();

    return (
        <Mark tone={STATE_TONE[state] ?? 'gray-subtle'} test="state">
            {t(`pricing::admin.state.${state}`)}
        </Mark>
    );
}

/** A row's photo, 40 px; a quiet square without one. */
export function RowPhoto({ src }: { src: string | null }) {
    return src !== null ? <img src={src} alt="" className="size-10 rounded-md border border-line object-cover" /> : <span className="block size-10 rounded-md bg-surface-sunken" aria-hidden="true" />;
}



/**
 * What every Pricing screen draws above its rows (§4.3.1, §4.3.2): the store filter (none for one store),
 * Off for a Super Admin's off store, the discount ceiling - a link to the settings for whoever may change
 * it (Q11) - and a wired store's Note.
 */
export function StoreFrame({ frame, wiredBody }: { frame: PricingFrameData; wiredBody?: string }) {
    const t = useT();
    const ceiling = t('pricing::admin.ceiling', { percent: frame.ceilingPercent });

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <StoreFilter stores={frame.stores} value={frame.storeCode} className="max-w-xs" />
                {frame.off ? <StoreOffBadge className="mb-2" /> : null}
            </div>
            <p className="text-copy-13 text-ink-muted" data-test="ceiling">
                {frame.maySetCeiling ? (
                    <Link href={`/admin/settings?store=${frame.storeCode}`} className="underline underline-offset-4 hover:text-ink">
                        {ceiling}
                    </Link>
                ) : (
                    ceiling
                )}
            </p>
            {frame.wired ? (
                <Note label={t('pricing::admin.wired.label')} data-test="wired">
                    {wiredBody ?? t('pricing::admin.wired.body')}
                </Note>
            ) : null}
        </div>
    );
}

/** "Showing 50 of 312" (Q6), above a list. */
export function Showing({ shown, total }: { shown: number; total: number }) {
    const t = useT();
    const locale = useLocale();

    return (
        <p className="tw-figure text-copy-13 text-ink-muted" aria-live="polite" data-test="showing">
            {t('pricing::admin.showing', { shown: figure(locale, shown), total: figure(locale, total) })}
        </p>
    );
}




/** A read that failed, said in words with its way to try again (the review of step 3). */
export function LoadFailed({ what, onRetry }: { what: 'note' | 'preview' | 'offer' | 'bands' | 'sizes' | 'categories' | 'search'; onRetry: () => void }) {
    const t = useT();

    return (
        <Note
            variant="error"
            size="small"
            alert
            action={
                <Button type="button" variant="outline" size="sm" onClick={onRetry} data-test="load-retry">
                    {t('pricing::admin.failed.try_again')}
                </Button>
            }
            data-test={`load-failed-${what}`}
        >
            {t(`pricing::admin.failed.${what}`)}
        </Note>
    );
}
