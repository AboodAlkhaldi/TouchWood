import { createContext, useContext, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { Note } from '@/components/Note';
import { StoreFilter } from '@/components/StoreFilter';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { figure, intlLocale } from '@/lib/digits';
import { translate, useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { InventoryFrameData, StockFiguresData } from '@/types/generated/Modules/Inventory/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import type { Problem } from './checks';

/*
| What Inventory's screens share (inventory.md §4.3.1): their words - on Inventory's own pages from the page,
| on a product's Stores tab from the section's data (InventoryWords) -, the frame above the rows (the store
| filter, Off, a wired store's Note), a size's one badge, the threshold as written, and a read that failed.
| Every piece is shadcn's parts under Geist's rules (frontend.md §1.11).
*/

export type Locale = 'ar' | 'en';
export type Translate = (key: string, values?: Record<string, string | number>) => string;

/** Inventory's words when they do not arrive with the page: a product's Stores tab sends them in its data. */
export const InventoryWords = createContext<Record<string, string> | null>(null);

/** The page's words, or the section's own where Inventory draws on another module's page. */
export function useT(): Translate {
    const page = useTranslator();
    const own = useContext(InventoryWords);

    return own === null ? page : (key, values = {}) => (own[key] === undefined ? page(key, values) : translate(own, key, values));
}

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/** The words of `inventory::admin_stock`. */
export const W = 'inventory::admin_stock';

/** A count's words in the page's plural form: a key holding zero, one, two, few, many, other. */
export function plural(t: Translate, locale: Locale, key: string, count: number, values: Record<string, string | number> = {}): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count), ...values });
}

/** A check's problem as its sentence. */
export function sentence(t: Translate, locale: Locale, problem: Problem | null): string | undefined {
    if (problem === null) {
        return undefined;
    }

    const values: Record<string, string> = {};

    for (const [name, value] of Object.entries(problem.values ?? {})) {
        values[name] = figure(locale, value);
    }

    return t(`inventory::admin_stock.check.${problem.key}`, values);
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar || en : en || ar) ?? '';
}

/** The other language's name, under the page's. */
export function otherName(locale: Locale, ar: string | null, en: string | null): string | null {
    return locale === 'ar' ? en : ar;
}

/** A size's values joined - "60 cm · Oak" - or "One Size". */
export function sizeName(t: Translate, values: string[]): string {
    return values.length === 0 ? t('inventory::admin_stock.one_size') : values.join(' · ');
}

/** A mark on a row, in words as well as colour (Geist's Badge). */
export function Mark({ tone: name, children, test }: { tone: Tone; children: ReactNode; test?: string }) {
    return (
        <Badge className={tone(name)} data-test={test}>
            {children}
        </Badge>
    );
}

const BADGE_TONE: Record<string, Tone> = { SHORT: 'red-subtle', OUT_OF_STOCK: 'red-subtle', ORDERABLE: 'gray-subtle', LOW: 'amber-subtle' };

/** A size's one badge (Q17, Q18): Short by 3, Out of Stock, Orderable or Low - the first that applies, or none. */
export function StockBadge({ figures }: { figures: StockFiguresData }) {
    const t = useT();
    const locale = useLocale();

    if (figures.badge === null) {
        return null;
    }

    return (
        <Mark tone={BADGE_TONE[figures.badge] ?? 'gray-subtle'} test="badge">
            {t(`inventory::admin_stock.badge.${figures.badge}`, { count: figure(locale, figures.shortBy) })}
        </Mark>
    );
}

/** Its threshold: its own, or "10 (Default)". */
export function threshold(t: Translate, locale: Locale, figures: StockFiguresData): string {
    return figures.ownThreshold === null ? t('inventory::admin_stock.threshold_default', { count: figure(locale, figures.threshold) }) : figure(locale, figures.threshold);
}

/** In stock, when it is not a box: the number - and in a wired store "From Provider" and when it last changed (Q23). */
export function InStock({ figures, wired }: { figures: StockFiguresData; wired: boolean }) {
    const t = useT();
    const locale = useLocale();

    return (
        <span className="grid gap-1">
            <span className="tw-figure">{figure(locale, figures.inStock)}</span>
            {wired ? (
                <span className="text-copy-12 text-ink-muted">
                    {t('inventory::admin_stock.from_provider')}
                    {figures.providerChangedAt !== null ? (
                        <>
                            {' · '}
                            <Time value={figures.providerChangedAt} inSentence />
                        </>
                    ) : null}
                </span>
            ) : null}
        </span>
    );
}

/** A row's photo, 40 px; a quiet square without one. */
export function RowPhoto({ src }: { src: string | null }) {
    return src !== null ? <img src={src} alt="" className="size-10 rounded-md border border-line object-cover" /> : <span className="block size-10 rounded-md bg-surface-sunken" aria-hidden="true" />;
}

/**
 * What every Inventory screen draws above its rows (§4.3.1): the store filter (none for one store), Off for a
 * Super Admin's off store, and a wired store's Note.
 */
export function StoreFrame({ frame }: { frame: InventoryFrameData }) {
    const t = useT();

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <StoreFilter stores={frame.stores} value={frame.storeCode} className="max-w-xs" />
                {frame.off ? <StoreOffBadge className="mb-2" /> : null}
            </div>
            {frame.wired ? (
                <Note label={t('inventory::admin_stock.wired.label')} data-test="wired">
                    {t('inventory::admin_stock.wired.body')}
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
            {t('inventory::admin_stock.showing', { shown: figure(locale, shown), total: figure(locale, total) })}
        </p>
    );
}

/** A read that failed, said in words with its way to try again. */
export function LoadFailed({ what, onRetry }: { what: 'history' | 'sizes' | 'size'; onRetry: () => void }) {
    const t = useT();

    return (
        <Note
            variant="error"
            size="small"
            alert
            action={
                <Button type="button" variant="outline" size="sm" onClick={onRetry} data-test="load-retry">
                    {t('inventory::admin_stock.failed.try_again')}
                </Button>
            }
            data-test={`load-failed-${what}`}
        >
            {t(`inventory::admin_stock.failed.${what}`)}
        </Note>
    );
}
