import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { StoreFilter } from '@/components/StoreFilter';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { isolate } from '@/lib/bidi';
import { figure, intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { FailureData, ReportedData, SyncFrameData } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { badValuesOf, sentText } from './checks';

/*
| What Sync's screens share (sync.md §4.1.1; pricing.md §4.3.1): their words, the frame above the rows (the
| store filter, Off), the badges - a report entry's kind, a read's kind and state, a store's state -, a
| failure as Y1 writes it (Sync's own reason in the page's language, or the provider's words as they came
| under "The provider said:"), and what an entry or a read's record is about - its What and its Detail, the
| same on the Sync Report and on a read's Reported section. Every piece is shadcn's parts under Geist's
| rules (frontend.md §1.11); nothing here draws a component of its own making.
*/

export type Locale = 'ar' | 'en';
export type Translate = (key: string, values?: Record<string, string | number>) => string;

/** The words of the Sync Report and a read's page. */
export const R = 'sync::admin_report';

export function useT(): Translate {
    return useTranslator();
}

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/** A count's words in the page's plural form: a key holding zero, one, two, few, many, other. */
export function plural(t: Translate, locale: Locale, key: string, count: number, values: Record<string, string | number> = {}): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count), ...values });
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar || en : en || ar) ?? '';
}

/** A size's values joined - "60 cm · Oak" - or "One Size". */
export function sizeName(t: Translate, values: string[]): string {
    return values.length === 0 ? t(`${R}.one_size`) : values.join(' · ');
}

/** Where a moment goes inside a sentence: the sentence is split there and the moment drawn between (as Staff's). */
export const MARK = '⁣';

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

/** A mark, in words as well as colour (Geist's Badge). */
export function Mark({ tone: name, children, test }: { tone: Tone; children: ReactNode; test?: string }) {
    return (
        <Badge className={tone(name)} data-test={test}>
            {children}
        </Badge>
    );
}

const KIND_TONE: Record<string, Tone> = { SALE_STOPPED: 'amber-subtle', DISCOUNT_SKIPPED: 'amber-subtle', READ_FAILED: 'red-subtle' };

/** A report entry's kind (§4.1.1): gray, but Offer Stopped and Discount Skipped amber, Read Failed red. */
export function KindBadge({ kind }: { kind: string }) {
    const t = useT();

    return (
        <Mark tone={KIND_TONE[kind] ?? 'gray-subtle'} test={`kind-${kind}`}>
            {t(`${R}.kinds.${kind}`)}
        </Mark>
    );
}

const READ_STATE_TONE: Record<string, Tone> = { QUEUED: 'gray-subtle', RUNNING: 'blue-subtle', DONE: 'green-subtle', FAILED: 'red-subtle' };

/** A read's state: Waiting gray, Running blue, Done green, Failed red. */
export function ReadStateBadge({ state }: { state: string }) {
    const t = useT();

    return (
        <Mark tone={READ_STATE_TONE[state] ?? 'gray-subtle'} test="read-state">
            {t(`${R}.read_states.${state}`)}
        </Mark>
    );
}

const STORE_STATE_TONE: Record<string, Tone> = { WORKING: 'green-subtle', FAILING: 'red-subtle', NOT_WIRED: 'gray-subtle' };

/** A store's state: Working green, Failing red, Not Wired gray. */
export function StoreStateBadge({ state }: { state: string }) {
    const t = useT();

    return (
        <Mark tone={STORE_STATE_TONE[state] ?? 'gray-subtle'} test="store-state">
            {t(`${R}.status.states.${state}`)}
        </Mark>
    );
}

/** How long a read took: "2 min 14 s", "14 s", or "—" until it is done. */
export function took(t: Translate, locale: Locale, seconds: number | null): string {
    if (seconds === null) {
        return t(`${R}.reads.none`);
    }

    const minutes = Math.floor(seconds / 60);

    return minutes === 0
        ? t(`${R}.reads.took_seconds`, { seconds: figure(locale, seconds) })
        : t(`${R}.reads.took`, { minutes: figure(locale, minutes), seconds: figure(locale, seconds % 60) });
}

/** Why a read failed or a store is failing (Q31): Sync's own reason in the page's words, or the provider's as it came. */
export function FailureText({ failure }: { failure: FailureData }) {
    const t = useT();

    if (failure.providerSaid !== null) {
        return (
            <span data-test="provider-said">
                {t(`${R}.status.provider_said`)}{' '}
                <span dir="auto" className="break-words">
                    {failure.providerSaid}
                </span>
            </span>
        );
    }

    return <span data-test="failure-text">{failure.text}</span>;
}

/**
 * What every Sync screen draws above its rows (§4.1; pricing.md §4.3.1): the store filter (none for one
 * store) and Off for a Super Admin's off store. Another store starts its list at the top, unfiltered.
 */
export function StoreFrame({ frame }: { frame: SyncFrameData }) {
    return (
        <div className="flex flex-wrap items-end gap-3">
            <StoreFilter stores={frame.stores} value={frame.storeCode} drop={['after', 'kind']} className="max-w-xs" />
            {frame.off ? <StoreOffBadge className="mb-2" /> : null}
        </div>
    );
}

/** "Showing 50 of 312" (Q6), above a list. */
export function Showing({ shown, total }: { shown: number; total: number }) {
    const t = useT();
    const locale = useLocale();

    return (
        <p className="tw-figure text-copy-13 text-ink-muted" aria-live="polite" data-test="showing">
            {t(`${R}.showing`, { shown: figure(locale, shown), total: figure(locale, total) })}
        </p>
    );
}

/** A list's empty state (shadcn's Empty, as every list's). */
export function EmptyState({ title, body, test }: { title: string; body: string; test: string }) {
    return (
        <Empty className="material-base" data-test={test}>
            <EmptyHeader>
                <EmptyTitle className="text-heading-16 text-ink">{title}</EmptyTitle>
                <EmptyDescription className="text-copy-14 text-ink-muted">{body}</EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}

/** A value the provider sent, as it came: "0", "12.5", "−3" - kept left to right inside a sentence. */
export function sent(t: Translate, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return t(`${R}.problems.details.nothing_sent`);
    }

    const text = typeof value === 'number' || typeof value === 'string' ? sentText(value) : String(value);

    return isolate(text);
}

/** What links an entry may carry: to the product's Stores tab, an offer on Offers, a discount, a read. */
export type Links = { store: string; mayViewProducts: boolean; mayViewOffers: boolean };

const LINK = 'text-label-14 text-ink underline-offset-4 hover:underline';

/**
 * **What** (Y1): the product and size with its code - the product a link to its page's Stores tab when the
 * reader may view products -, a size deleted since, or the provider's code (mono) for one we don't have.
 */
export function What({ about, links }: { about: ReportedData; links: Links }) {
    const t = useT();
    const locale = useLocale();
    const code =
        about.code === null ? null : (
            <span className="tw-figure font-mono text-copy-13 text-ink-muted" dir="ltr" data-test="what-code">
                {about.code}
            </span>
        );

    if (about.variantId === null) {
        return code ?? <span className="text-ink-subtle">—</span>;
    }

    if (about.sizeExists !== true || about.productId === null) {
        return (
            <span className="grid gap-0.5">
                <span className="text-copy-14 text-ink-muted" data-test="size-gone">
                    {t(`${R}.problems.size_gone`)}
                </span>
                {code}
            </span>
        );
    }

    const name = nameIn(locale, about.productNameAr, about.productNameEn);

    return (
        <span className="grid gap-0.5">
            {links.mayViewProducts ? (
                <Link href={`/admin/products/${about.productId}?tab=stores`} className={LINK} data-test="what-product">
                    {name}
                </Link>
            ) : (
                <span className="text-label-14 text-ink" data-test="what-product">
                    {name}
                </span>
            )}
            <span className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                <span>{sizeName(t, about.values)}</span>
                {code}
            </span>
        </span>
    );
}

/** **Detail per kind** (Y1): what the provider sent, what happened, and where to look. */
export function Detail({ about, links }: { about: ReportedData; links: Links }) {
    const t = useT();
    const D = `${R}.problems.details`;
    const detail = about.detail ?? {};

    switch (about.kind) {
        case 'UNKNOWN_CODE':
            return <span>{t(`${D}.sent`, { price: sent(t, detail.price), stock: sent(t, detail.stock) })}</span>;
        case 'NOT_IN_PROVIDER':
            return <span>{t(`${D}.not_at_provider`)}</span>;
        case 'ARCHIVED_SKIPPED':
            return <span>{t(`${D}.archived`)}</span>;
        case 'OFF_OR_DRAFT_PRICED':
            return <span>{t(`${D}.off_or_draft`, { price: sent(t, detail.price) })}</span>;
        case 'SALE_STOPPED': {
            const outcome = typeof detail.outcome === 'string' ? detail.outcome.toLowerCase() : null;

            return (
                <span className="grid gap-0.5">
                    {about.offer === null || !about.offer.exists ? (
                        <span className="text-ink-muted" data-test="offer-removed">
                            {t(`${D}.offer_removed`)}
                        </span>
                    ) : links.mayViewOffers ? (
                        <Link href={`/admin/offers?store=${links.store}&sale=${about.offer.id}`} className={LINK} data-test="offer-link">
                            {about.offer.description}
                        </Link>
                    ) : (
                        <span data-test="offer-link">{about.offer.description}</span>
                    )}
                    {outcome === 'left' || outcome === 'ended' || outcome === 'removed' ? <span>{t(`${D}.outcomes.${outcome}`)}</span> : null}
                </span>
            );
        }
        case 'BAD_VALUE':
            // What the server says was refused - the price, the stock or both, each in its own sentence; one not
            // sent at all said so, with no value.
            return (
                <span className="grid gap-0.5">
                    {badValuesOf(detail).map((refused) => (
                        <span key={refused} data-test="bad-value">
                            {refused === 'twice' || refused === 'missing_price' || refused === 'missing_stock'
                                ? t(`${D}.${refused}`)
                                : t(`${D}.${refused}`, { value: sent(t, refused === 'bad_price' ? detail.price : detail.stock) })}
                        </span>
                    ))}
                </span>
            );
        case 'READ_FAILED':
            return (
                <span className="grid gap-0.5">
                    {about.readId === null ? (
                        <span>{t(`${D}.read_failed`)}</span>
                    ) : (
                        <Link href={`/admin/sync/reads/${about.readId}`} className={LINK} data-test="failed-read-link">
                            {t(`${D}.read_failed`)}
                        </Link>
                    )}
                    {about.failure === null ? null : (
                        <span className="text-copy-13 text-ink-muted">
                            <FailureText failure={about.failure} />
                        </span>
                    )}
                </span>
            );
        case 'DISCOUNT_SKIPPED':
            // A discount deleted since is said so, without a link, as a stopped offer's is (pricing.md §9.4 Q37) - only
            // one known to be gone: an entry naming no discount says nothing of one.
            return (
                <span className="grid gap-0.5">
                    {about.discount === null ? null : !about.discount.exists ? (
                        <span className="text-ink-muted" data-test="discount-removed">
                            {t(`${D}.discount_removed`)}
                        </span>
                    ) : about.discount.description !== null ? (
                        links.mayViewOffers ? (
                            <Link href={`/admin/category-discounts/${about.discount.id}`} className={LINK} data-test="discount-link">
                                {about.discount.description}
                            </Link>
                        ) : (
                            <span data-test="discount-link">{about.discount.description}</span>
                        )
                    ) : null}
                    <span>{t(`${D}.discount_skipped`)}</span>
                </span>
            );
        default:
            return null;
    }
}
