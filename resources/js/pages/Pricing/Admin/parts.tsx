import { createContext, forwardRef, useContext, useEffect, useRef, useState, type ComponentProps, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, MoreHorizontal } from 'lucide-react';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { formatDate } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { figure, intlLocale } from '@/lib/digits';
import { translate, useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { PricingFrameData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { ltr, plural, type Locale, type Translate } from './checks';

export { ltr, plural, type Locale, type Translate };

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

/** A row's ⋯ button: its name says whose actions, and a spinner stands in while one is on its way. */
export const MoreButton = forwardRef<HTMLButtonElement, ComponentProps<typeof Button> & { name: string; busy?: boolean }>(function MoreButton({ name, busy = false, ...rest }, ref) {
    const t = useTranslator();

    return (
        <Button ref={ref} type="button" variant="ghost" size="icon-sm" aria-label={`${t('ui.more_actions')}: ${name}`} aria-busy={busy || undefined} title={t('ui.more_actions')} {...rest}>
            {busy ? <Spinner aria-hidden="true" role={undefined} aria-label={undefined} /> : <MoreHorizontal aria-hidden="true" />}
        </Button>
    );
});

/**
 * A ⋯ item that may be out of reach: drawn, its reason written under it (Catalog's Guarded). Destructive
 * ones in red, last after a separator.
 */
export function Guarded({ label, reason, onSelect, test, destructive = false }: { label: string; reason?: string | null; onSelect: () => void; test: string; destructive?: boolean }) {
    const off = reason !== undefined && reason !== null;

    return (
        <DropdownMenuItem
            variant={destructive ? 'destructive' : 'default'}
            aria-disabled={off || undefined}
            className={off ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (off) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={off ? 'opacity-60' : undefined}>{label}</span>
                {off ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
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

/** A value that follows another after a pause - a search typed, a dialog's fields for its note. */
export function useDebounced<T>(value: T, delay = 300): T {
    const [settled, setSettled] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), delay);

        return () => clearTimeout(timer);
    }, [value, delay]);

    return settled;
}

/** A read the page asks as it is typed: JSON, in the panel's session; null when it is refused or fails. */
export async function readJson<T>(path: string, params: Record<string, string | string[] | boolean | null | undefined>, signal?: AbortSignal): Promise<T | null> {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            value.forEach((each) => query.append(`${key}[]`, each));
        } else if (typeof value === 'boolean') {
            if (value) {
                query.set(key, '1');
            }
        } else if (value !== null && value !== undefined && value !== '') {
            query.set(key, value);
        }
    }

    try {
        const response = await fetch(`${path}?${query.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal });

        return response.ok ? ((await response.json()) as T) : null;
    } catch {
        return null;
    }
}

/**
 * A read that follows what is typed (a dialog's note or preview, a picker's search): it keeps its last
 * answer while the next one is on its way (`loading`), and a later answer always wins over an earlier one.
 *
 * @param params  null while there is nothing to ask yet
 */
export function useLiveRead<T>(path: string, params: Record<string, string | string[] | boolean | null | undefined> | null, delay = 300) {
    const settled = useDebounced(params === null ? null : JSON.stringify(params), delay);
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    // Each read numbered: only the latest one's answer is taken, whatever order the answers arrive in.
    const latest = useRef(0);

    useEffect(() => {
        const mine = ++latest.current;

        if (settled === null) {
            setData(null);
            setLoading(false);
            setFailed(false);

            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        readJson<T>(path, JSON.parse(settled) as Record<string, string | string[] | boolean | null>, controller.signal).then((answer) => {
            if (controller.signal.aborted || mine !== latest.current) {
                return;
            }

            setLoading(false);
            // Refused (403, 404, an invalid input) or failed: said, never a spinner forever or nothing at all.
            setFailed(answer === null);
            setData(answer);
        });

        return () => controller.abort();
    }, [path, settled, attempt]);

    return { data, loading, failed, retry: () => setAttempt((count) => count + 1) };
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
