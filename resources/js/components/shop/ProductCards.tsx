import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Clock, Flame, Heart, LayoutGrid, List, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { ActionButton } from '@/components/ActionButton';
import { ShopStars } from '@/components/feedback/shop';
import { Money } from '@/components/Money';
import { Button } from '@/components/ui/button';
import { figure, intlLocale } from '@/lib/digits';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { isReduced } from '@/components/content/ThemeMarks';
import { percentOff, ShopPrice } from '@/components/shop/ShopPrice';
import { useLink } from '@/lib/routes';
import { keptBeside } from '@/lib/use-load-more';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The shop's product cards (catalog.md §4.5): photo, name and the labels the store attached, in the
| list's order (amendment 1(g)), each as Geist's Badge in its tone - and "Last pieces" while a size
| this viewer can buy and order now is ending soon (amendment 16(h)). Its stars and count where the
| store's lists show ratings and it has a review (amendment 25; feedback.md §4.1 F7). Its price for this
| viewer (amendment 35; sales.md §4.1 S1). No stock, no Add to Cart (R1-9), no code.
|
| What other modules add to a card (amendment 32) comes beside the cards as `facts`: Feedback's Save
| heart, filled where the signed-in customer saved it here (feedback.md §4.1 F7, Q17 (b)) - a button
| beside the card's link, never inside it. A guest who presses it is sent to sign in and comes back with
| it saved (Q18 (a)).
*/

/** `cardFacts` as the pages carry them: product id => `{module}.{key}` => what it adds; an empty map arrives as []. */
export type CardFacts = Record<string, Record<string, Record<string, unknown>>>;

const HEART_PRESSED = 'shop:heart-pressed';

/**
 * A list's facts as Show More adds pages: each answer covers its own cards only, so they are kept and
 * merged, the newest over the older. A list keeps them beside its rows, so going Back brings them too
 * (`firstRow`: the first card of the page these facts came with; lib/use-load-more.ts).
 */
export function useCardFacts(facts: CardFacts | unknown[] | undefined, firstRow?: string): CardFacts {
    const { url } = usePage();
    const kept = () => keptBeside(url, firstRow) as CardFacts | undefined;
    const [all, setAll] = useState<CardFacts>(() => ({ ...kept(), ...(Array.isArray(facts) ? {} : facts) }));

    useEffect(() => {
        if (!Array.isArray(facts) && facts !== undefined) {
            setAll((before) => ({ ...kept(), ...before, ...facts }));
        }
        // Only a new answer changes them.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [facts]);

    // A press's answer covers the first page's cards only: the card pressed says its own.
    useEffect(() => {
        const pressed = (event: Event) => {
            const { productId, saved } = (event as CustomEvent<{ productId: string; saved: boolean }>).detail;
            setAll((before) => ({ ...before, [productId]: { ...before[productId], 'feedback.saved': { saved } } }));
        };
        window.addEventListener(HEART_PRESSED, pressed);

        return () => window.removeEventListener(HEART_PRESSED, pressed);
    }, []);

    return all;
}

/** How many columns a list's cards take from a laptop up: 4 in a category's box, 5 for the home's best sellers (shop-design.md §3.2, §5.3). */
type Columns = 4 | 5;

export function ProductCards({ cards, test = 'card', facts, columns = 4 }: { cards: ShopCardData[]; test?: string; /** Present where the page carries `cardFacts`: the hearts are drawn. */ facts?: CardFacts; columns?: Columns }) {
    return (
        <ul className={`grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-[18px] ${columns === 5 ? 'lg:grid-cols-4 xl:grid-cols-5' : 'lg:grid-cols-4'}`}>
            {cards.map((card) => (
                <li key={card.productId} className="relative">
                    <ProductCard card={card} test={test} />
                    {facts === undefined ? null : <CardHeart productId={card.productId} name={card.name} saved={facts[card.productId]?.['feedback.saved']?.saved === true} />}
                </li>
            ))}
        </ul>
    );
}

/** A flag over the photo (shop-design.md §4.3): strong ones solid, soft ones with a thin white edge to stand off a light photo. */
export type Flag = { key: string; text: string; className: string; icon?: 'clock' | 'flame'; test: string };

/** The flags in their order (P19): the campaign's word, Last pieces, Best seller, New arrival, then the store's labels. The product page shows them all (§6.3). */
export function flagsOf(card: Pick<ShopCardData, 'lastPieces' | 'bestSeller' | 'newArrival' | 'labels'>, reduced: boolean, themeWord: string | null, t: ReturnType<typeof useTranslator>): Flag[] {
    const soft = 'ring-1 ring-white/90';

    return [
        ...(reduced && themeWord ? [{ key: 'theme', text: themeWord, className: 'bg-brand text-ink-on-brand', test: 'theme-badge' }] : []),
        ...(card.lastPieces ? [{ key: 'last', text: t('catalog::shop.last_pieces'), className: 'bg-accent text-ink-on-brand', icon: 'clock' as const, test: 'last-pieces' }] : []),
        ...(card.bestSeller ? [{ key: 'best', text: t('catalog::shop.flag.best_seller'), className: `bg-warn-soft text-warn ${soft}`, icon: 'flame' as const, test: 'flag-best-seller' }] : []),
        ...(card.newArrival ? [{ key: 'new', text: t('catalog::shop.flag.new_arrival'), className: `bg-good-soft text-good ${soft}`, test: 'flag-new-arrival' }] : []),
        // A label keeps the tone staff chose (P18); a subtle one gets the white edge too.
        ...card.labels.map((label, index) => ({ key: `label${index}`, text: label.name, className: `${tone(label.tone as Tone)} ${label.tone.endsWith('-subtle') ? soft : ''}`, test: 'card-label' })),
    ];
}

/** One flag; `large` on the product page. */
export function FlagBadge({ flag, className = '', large = false }: { flag: Flag; className?: string; large?: boolean }) {
    return (
        <span className={`inline-flex items-center gap-1 rounded-md font-semibold ${large ? 'px-2 py-0.5 text-label-12' : 'px-1.5 py-0.5 text-[11px] leading-4'} ${flag.className} ${className}`} data-test={flag.test}>
            {flag.icon === 'clock' ? <Clock aria-hidden="true" className="size-3" /> : null}
            {flag.icon === 'flame' ? <Flame aria-hidden="true" className="size-3" /> : null}
            {flag.text}
        </span>
    );
}

function ProductCard({ card, test }: { card: ShopCardData; test: string }) {
    const t = useTranslator();
    const link = useLink();
    const { locale, frame, staffView } = usePage<SharedProps>().props;
    const href = link('storefront.catalog.product', { slug: card.slug });
    // A live theme marks every product showing a reduced price now (content.md Q9 (a)): its word is a flag (§4.3).
    const reduced = isReduced(card.price?.now, card.price?.before);
    const flags = flagsOf(card, reduced, frame?.content?.theme?.badge ?? null, t);
    const off = card.price?.now && card.price.before ? percentOff(card.price.now, card.price.before) : 0;
    // The photo and the next ones, which a desktop's pointer moves through (C14, P4).
    const photos = [card.photo, ...(card.morePhotos as ShopCardData['photo'][])];
    const [shown, setShown] = useState(0);
    const many = photos.length > 1;

    return (
        <article className="group grid h-full content-start overflow-hidden sm:rounded-xl sm:border sm:border-line sm:bg-surface sm:hover:shadow-[0_0_0_1px_var(--tw-line-strong)]" data-test={test}>
            <Link
                href={href}
                className="relative block aspect-square overflow-hidden rounded-xl bg-surface-sunken sm:rounded-none"
                // The name's link below is the card's one for a screen reader and the keyboard: this one repeats it.
                aria-hidden="true"
                tabIndex={-1}
                onMouseMove={(event) => {
                    if (!many) {
                        return;
                    }

                    const box = event.currentTarget.getBoundingClientRect();
                    const along = (event.clientX - box.left) / box.width;
                    const part = locale === 'ar' ? 1 - along : along;
                    setShown(Math.min(photos.length - 1, Math.max(0, Math.floor(part * photos.length))));
                }}
                onMouseLeave={() => setShown(0)}
                data-test={`${test}-photo`}
            >
                {/* The whole product, never cropped, on a grey well (C15). */}
                <ShopPhoto photo={photos[shown] ?? card.photo} size="card" alt="" className="size-full object-contain" />
                {flags.length > 0 ? (
                    <span className="absolute start-2 top-2 flex flex-col items-start gap-1" data-test="card-flags">
                        {flags.slice(0, 4).map((flag, index) => (
                            // 4 on a computer, 3 on a phone (P19); the product page shows them all.
                            <FlagBadge key={flag.key} flag={flag} className={index === 3 ? 'max-sm:hidden' : ''} />
                        ))}
                    </span>
                ) : null}
                {/* The sale's percent at the photo's bottom corner, outside the flags' cap (§4.3). */}
                {off > 0 ? (
                    <span className="absolute start-2 bottom-2 rounded-md bg-bad px-1.5 py-0.5 text-label-13 font-semibold text-ink-on-brand" data-test="card-off">
                        {t('catalog::shop.price.off', { percent: figure(locale, off) })}
                    </span>
                ) : null}
                {many ? (
                    <span aria-hidden="true" className="absolute inset-x-3 bottom-1.5 hidden gap-1 group-hover:flex max-sm:!hidden" data-test="card-photo-bars">
                        {photos.map((_, index) => (
                            <i key={index} className={`h-0.5 flex-1 rounded-full ${index === shown ? 'bg-ink' : 'bg-ink/20'}`} />
                        ))}
                    </span>
                ) : null}
            </Link>

            <div className="grid content-start gap-1 pt-2 sm:p-3">
                {card.brand ? <span className="truncate text-label-12 text-ink-muted" data-test="card-brand">{card.brand}</span> : null}
                {/* Two lines kept, so cards line up. */}
                <Link href={href} className="line-clamp-2 min-h-[2lh] text-[12px] leading-snug text-ink hover:underline sm:text-label-14" data-test="card-name">
                    {card.name}
                    {/* The flags and the percent off, read with the card (shop-design.md §4.3): drawn over the photo, hidden there. */}
                    {flags.length > 0 || off > 0 ? <span className="sr-only">{[...flags.map((flag) => flag.text), ...(off > 0 ? [t('catalog::shop.price.off', { percent: figure(locale, off) })] : [])].map((text) => `, ${text}`).join('')}</span> : null}
                </Link>
                <CardPriceLine card={card} />
                {card.rating === null || card.ratingCount === 0 ? null : (
                    <span className="inline-flex items-center gap-1.5" data-test="card-rating">
                        <ShopStars rating={card.rating} className="size-3.5" label={t(`catalog::shop.card_rating.${new Intl.PluralRules(intlLocale(locale)).select(card.ratingCount)}`, { rating: new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: 1 }).format(card.rating), count: figure(locale, card.ratingCount) })} />
                        <span className="tw-figure text-copy-13 text-ink-muted" aria-hidden="true">
                            ({figure(locale, card.ratingCount)})
                        </span>
                    </span>
                )}
                <CardButton card={card} href={href} staffView={Boolean(staffView)} />
            </div>
        </article>
    );
}

/**
 * The price with its unit (shop-design.md §4.1, §4.2): 19 px; on a sale the price in red at 21 px, the old price under it,
 * readable, and «وفّر» what a piece saves. Then the wholesale line, always given its row so cards line up.
 */
function CardPriceLine({ card }: { card: ShopCardData }) {
    const t = useTranslator();
    const price = card.price;
    const unit = t(`catalog::shop.unit.${card.unit}`);
    const individual = price !== null && price.wholesaleFrom === null && card.sellsWholesale;

    return (
        <span className="grid gap-0.5" data-test="card-price">
            {price === null ? null : price.from !== null ? (
                <span className="flex flex-wrap items-baseline gap-x-1.5" data-test="price-from">
                    <span className="text-copy-13 text-ink-muted">{t('catalog::shop.price.from')}</span>
                    <Money value={price.from} className="text-[14.5px] font-semibold text-brand sm:text-[19px]" />
                    <span className="text-copy-13 text-ink-muted">/ {unit}</span>
                </span>
            ) : price.now !== null && price.saving !== null && price.before !== null ? (
                <>
                    <span className="flex flex-wrap items-baseline gap-x-1.5" data-test="price-now">
                        <Money value={price.now} className="text-[16px] font-semibold text-bad sm:text-[21px]" />
                        <span className="text-copy-13 text-ink-muted">/ {unit}</span>
                    </span>
                    <span className="flex flex-wrap items-baseline gap-x-2">
                        <s className="text-[13px] text-ink-muted decoration-[1.5px] sm:text-[15px]" data-test="price-before">
                            <Money value={price.before} />
                        </s>
                        {/* Left off a phone's card, where it does not fit (P5). */}
                        <span className="text-copy-13 font-medium text-good max-sm:hidden" data-test="price-saving">
                            {t('catalog::shop.saving', { amount: price.saving.text })}
                        </span>
                    </span>
                </>
            ) : price.now !== null ? (
                <span className="flex flex-wrap items-baseline gap-x-1.5" data-test="price-now">
                    <Money value={price.now} className="text-[14.5px] font-semibold text-brand sm:text-[19px]" />
                    <span className="text-copy-13 text-ink-muted">/ {unit}</span>
                </span>
            ) : null}
            <span className="min-h-[1lh] text-copy-13 text-ink-muted" data-test="card-wholesale">
                {price?.wholesaleFrom ? (
                    <span className="flex flex-wrap items-center gap-x-1.5" data-test="wholesale-from">
                        <span>{t('catalog::shop.price.wholesale_from')}</span>
                        {price.wholesaleFromBefore !== null ? <ShopPrice now={price.wholesaleFrom} before={price.wholesaleFromBefore} /> : <Money value={price.wholesaleFrom} />}
                    </span>
                ) : individual ? (
                    t('catalog::shop.card.wholesale_individual')
                ) : null}
            </span>
        </span>
    );
}

/**
 * The card's button (shop-design.md §4.1; reverses sales.md S2's "never on a card"): one size this viewer can order now -
 * Add to cart, what the product page would start with (D3); several - Choose options, the product page. A staff view,
 * which orders nothing, opens the page (P20). On a phone a 32 px «+».
 */
function CardButton({ card, href, staffView }: { card: ShopCardData; href: string; staffView: boolean }) {
    const t = useTranslator();
    const link = useLink();
    const [busy, setBusy] = useState(false);
    const one = staffView ? null : (card.oneSize as { variant: string; mode: string; minimum: number } | null);

    if (one === null) {
        return (
            <>
                <Button asChild variant="outline" className="mt-1 hidden h-10 border-brand sm:inline-flex">
                    <Link href={href} data-test="card-choose">
                        {t('catalog::shop.card.choose')}
                    </Link>
                </Button>
                <Button asChild variant="outline" size="icon" className="mt-1 size-8 justify-self-end rounded-full sm:hidden" aria-label={t('catalog::shop.card.choose_name', { name: card.name })}>
                    <Link href={href}>
                        <Plus aria-hidden="true" />
                    </Link>
                </Button>
            </>
        );
    }

    const add = () =>
        router.post(link('storefront.sales.cart.add'), { variant: one.variant, mode: one.mode, quantity: one.minimum } as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: (page) => {
                const errors = (page.props as { errors?: Record<string, string> }).errors ?? {};

                // A refusal is said by the shop's toasts from the answer's errors; an add, here.
                if (Object.keys(errors).length === 0) {
                    toast.success(t('catalog::shop.card.added', { name: card.name }));
                }
            },
        });

    return (
        <>
            <ActionButton type="button" loading={busy} onClick={add} className="mt-1 hidden h-10 sm:inline-flex" data-test="card-add">
                {t('catalog::shop.card.add')}
            </ActionButton>
            <ActionButton type="button" size="icon" loading={busy} onClick={add} className="mt-1 size-8 justify-self-end rounded-full sm:hidden" aria-label={t('catalog::shop.card.add_name', { name: card.name })} data-test="card-add-phone">
                <Plus aria-hidden="true" />
            </ActionButton>
        </>
    );
}

/**
 * The Save heart over a card's photo (shop-design.md §4.4; feedback.md F7): 32 px on a white disc at the top-end corner.
 * **No spinner**: pressed, it fills or empties at once and the save goes in the background; a failed save puts it back and
 * says so. Pressed twice quickly, the last press wins (P6). A guest's press still goes to sign in (Q18 (a)).
 */
function CardHeart({ productId, name, saved }: { productId: string; name: string; saved: boolean }) {
    const t = useTranslator();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const [on, setOn] = useState(saved);
    const pressing = useRef(0);

    useEffect(() => setOn(saved), [saved]);

    function press() {
        const was = on;
        const mine = ++pressing.current;
        // Only the last press may undo: an earlier one's answer must not overturn a later choice.
        const undo = (say: boolean) => {
            if (shopper && mine === pressing.current) {
                setOn(was);
                window.dispatchEvent(new CustomEvent(HEART_PRESSED, { detail: { productId, saved: was } }));

                if (say) {
                    toast.error(t('catalog::shop.card.save_failed', { name }));
                }
            }
        };

        if (shopper) {
            setOn(!was);
            window.dispatchEvent(new CustomEvent(HEART_PRESSED, { detail: { productId, saved: !was } }));
        }

        router.post(
            link(was ? 'storefront.feedback.remove' : 'storefront.feedback.save', { product: productId }),
            { back: window.location.pathname + window.location.search },
            {
                only: ['cardFacts'],
                preserveScroll: true,
                // A refusal is said by the shop's toasts from its errors; a server error or a lost connection, here.
                onError: () => undo(false),
                onHttpException: () => undo(true),
                onNetworkError: () => undo(true),
            },
        );
    }

    return (
        <button
            type="button"
            aria-pressed={on}
            aria-label={on ? t('catalog::shop.card_save.remove', { name }) : t('catalog::shop.card_save.add', { name })}
            onClick={press}
            className="absolute end-2 top-2 grid size-8 place-items-center rounded-full bg-white shadow-sm hover:bg-white/90"
            data-test="card-heart"
        >
            <Heart aria-hidden="true" className={`size-4 ${on ? 'fill-red-500 text-red-500' : 'text-on-photo-ink'}`} />
        </button>
    );
}

/** Grid or the technical list (shop-design.md §5.3, C7); the choice is the browser's, kept for the next page (P7). */
export type ShopView = 'grid' | 'list';

const VIEW_KEY = 'shop:view';

/** The grid on the server's first draw and until the browser says otherwise, so the two draws agree. */
export function useShopView(): [ShopView, (view: ShopView) => void] {
    const [view, setView] = useState<ShopView>('grid');
    useEffect(() => {
        try {
            if (window.localStorage.getItem(VIEW_KEY) === 'list') {
                setView('list');
            }
        } catch {
            // No storage (a private window, blocked site data): the grid, every time.
        }
    }, []);

    return [
        view,
        (next: ShopView) => {
            setView(next);

            try {
                window.localStorage.setItem(VIEW_KEY, next);
            } catch {
                // Kept for this page only.
            }
        },
    ];
}

/** The switch, from a tablet up: a phone always shows the grid, the list's columns not fitting it. */
export function ViewSwitch({ view, onChange }: { view: ShopView; onChange: (view: ShopView) => void }) {
    const t = useTranslator();

    return (
        <span className="hidden items-center rounded-md border border-line p-0.5 md:inline-flex" role="group" aria-label={t('catalog::shop.view.label')} data-test="view-switch">
            {(['grid', 'list'] as const).map((each) => (
                <Button
                    key={each}
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={`size-8 ${view === each ? 'bg-surface-sunken text-ink' : 'text-ink-muted'}`}
                    aria-pressed={view === each}
                    aria-label={t(`catalog::shop.view.${each}`)}
                    onClick={() => onChange(each)}
                    data-test={`view-${each}`}
                >
                    {each === 'grid' ? <LayoutGrid aria-hidden="true" /> : <List aria-hidden="true" />}
                </Button>
            ))}
        </span>
    );
}

/**
 * The technical list for contractors (shop-design.md §5.3, C7): photo · product and brand · size · how sold · price · the
 * card's button. No code, no stock. Drawn from a tablet up; on a phone the page shows the grid.
 */
export function ProductRows({ cards, test = 'card-row' }: { cards: ShopCardData[]; test?: string }) {
    const t = useTranslator();
    const link = useLink();
    const { locale, staffView } = usePage<SharedProps>().props;
    const head = 'border-b border-line px-3 py-2 text-start font-medium';
    const cell = 'border-b border-line px-3 py-3';

    return (
        <table className="w-full border-separate border-spacing-0 text-start" data-test="product-rows">
            <thead>
                <tr className="text-label-12 text-ink-muted">
                    <th scope="col" className={`w-20 ${head} ps-0`}>
                        <span className="sr-only">{t('catalog::shop.view.photo')}</span>
                    </th>
                    <th scope="col" className={head}>{t('catalog::shop.view.product')}</th>
                    <th scope="col" className={`w-28 ${head}`}>{t('catalog::shop.view.size')}</th>
                    <th scope="col" className={`w-36 ${head}`}>{t('catalog::shop.filters.sides')}</th>
                    <th scope="col" className={`w-48 ${head}`}>{t('catalog::shop.view.price')}</th>
                    <th scope="col" className={`w-44 ${head} pe-0`}>
                        <span className="sr-only">{t('catalog::shop.view.buy')}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {cards.map((card) => {
                    const href = link('storefront.catalog.product', { slug: card.slug });
                    // How it is sold to this viewer: an individual's wholesale line is not theirs to buy (the card says so).
                    const retail = card.price !== null && (card.price.now !== null || card.price.from !== null);
                    const wholesale = card.price !== null && card.price.wholesaleFrom !== null;

                    return (
                        <tr key={card.productId} className="align-middle" data-test={test}>
                            <td className={`${cell} ps-0`}>
                                <Link href={href} className="block size-16 overflow-hidden rounded-md bg-surface-sunken" aria-label={card.name} tabIndex={-1}>
                                    <ShopPhoto photo={card.photo} size="card" alt="" className="size-full object-contain" />
                                </Link>
                            </td>
                            <td className={cell}>
                                {card.brand ? <span className="block text-label-12 text-ink-muted">{card.brand}</span> : null}
                                <Link href={href} className="line-clamp-2 text-label-14 text-ink hover:underline" data-test="card-name">
                                    {card.name}
                                </Link>
                            </td>
                            <td className={`tw-figure ${cell} text-copy-13 text-ink`} data-test="row-sizes">
                                {card.sizes <= 1 ? t('catalog::shop.view.one_size') : t('catalog::shop.view.sizes', { count: figure(locale, card.sizes) })}
                            </td>
                            <td className={`${cell} text-copy-13 text-ink`} data-test="row-sold">
                                {[retail ? t('catalog::shop.filters.retail') : null, wholesale ? t('catalog::shop.filters.wholesale') : null].filter(Boolean).join(' · ')}
                            </td>
                            <td className={cell}>
                                <CardPriceLine card={card} />
                            </td>
                            <td className={`${cell} pe-0`}>
                                <span className="grid">
                                    <CardButton card={card} href={href} staffView={Boolean(staffView)} />
                                </span>
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

/**
 * Goes With as the design's compact rows (shop-design.md §6.5; catalog.md §1.10): photo, name, price, and the card's button -
 * Add for one size, Choose for several. No bundle price (C3).
 */
export function CompactRows({ cards, test = 'card' }: { cards: ShopCardData[]; test?: string }) {
    const link = useLink();
    const { staffView } = usePage<SharedProps>().props;

    return (
        <ul className="grid gap-3 md:grid-cols-2">
            {cards.map((card) => {
                const href = link('storefront.catalog.product', { slug: card.slug });

                return (
                    <li key={card.productId} className="grid grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-line bg-surface p-2.5" data-test={test}>
                        <Link href={href} className="block size-18 overflow-hidden rounded-md bg-surface-sunken" aria-label={card.name} tabIndex={-1}>
                            <ShopPhoto photo={card.photo} size="card" alt="" className="size-full object-contain" />
                        </Link>
                        <span className="grid min-w-0 gap-0.5">
                            <Link href={href} className="line-clamp-2 text-label-14 text-ink hover:underline" data-test="card-name">
                                {card.name}
                            </Link>
                            <CardPriceLine card={card} />
                        </span>
                        <span className="grid w-32 max-sm:w-auto">
                            <CardButton card={card} href={href} staffView={Boolean(staffView)} />
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}
