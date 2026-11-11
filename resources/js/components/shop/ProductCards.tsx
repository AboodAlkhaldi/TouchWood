import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { ShopStars } from '@/components/feedback/shop';
import { Badge } from '@/components/ui/badge';
import { figure, intlLocale } from '@/lib/digits';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { isReduced, ThemeBadge, ThemeFlag } from '@/components/content/ThemeMarks';
import { CardPrice } from '@/components/shop/ShopPrice';
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

export function ProductCards({ cards, test = 'card', facts }: { cards: ShopCardData[]; test?: string; /** Present where the page carries `cardFacts`: the hearts are drawn. */ facts?: CardFacts }) {
    return (
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {cards.map((card) => (
                <li key={card.productId} className="relative">
                    <ProductCard card={card} test={test} />
                    {facts === undefined ? null : <CardHeart productId={card.productId} name={card.name} saved={facts[card.productId]?.['feedback.saved']?.saved === true} />}
                </li>
            ))}
        </ul>
    );
}

function ProductCard({ card, test }: { card: ShopCardData; test: string }) {
    const t = useTranslator();
    const link = useLink();
    const locale = usePage<SharedProps>().props.locale;
    // A live theme marks every product showing a reduced price now (content.md Q9 (a)).
    const reduced = isReduced(card.price?.now, card.price?.before);

    return (
        <Link
            href={link('storefront.catalog.product', { slug: card.slug })}
            className="material-small group grid h-full content-start gap-3 overflow-hidden bg-surface p-3 hover:shadow-[0_0_0_1px_var(--tw-line-strong)]"
            data-test={test}
        >
            <span className="relative block">
                <ShopPhoto photo={card.photo} size="card" alt={card.name} className="aspect-square w-full rounded-md" />
                <ThemeFlag reduced={reduced} />
            </span>
            <span className="text-label-14 text-ink group-hover:underline">{card.name}</span>
            <CardPrice price={card.price} />
            {card.rating === null || card.ratingCount === 0 ? null : (
                <span className="inline-flex items-center gap-1.5" data-test="card-rating">
                    <ShopStars rating={card.rating} className="size-3.5" label={t(`catalog::shop.card_rating.${new Intl.PluralRules(intlLocale(locale)).select(card.ratingCount)}`, { rating: new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: 1 }).format(card.rating), count: figure(locale, card.ratingCount) })} />
                    <span className="tw-figure text-copy-13 text-ink-muted" aria-hidden="true">
                        ({figure(locale, card.ratingCount)})
                    </span>
                </span>
            )}
            {card.labels.length === 0 && !card.lastPieces && !reduced ? null : (
                <span className="flex flex-wrap gap-1 empty:hidden">
                    <ThemeBadge reduced={reduced} />
                    {card.lastPieces ? (
                        <Badge className={tone('amber')} data-test="last-pieces">
                            {t('catalog::shop.last_pieces')}
                        </Badge>
                    ) : null}
                    {card.labels.map((label) => (
                        <Badge key={label.name} className={tone(label.tone as Tone)} data-test="card-label">
                            {label.name}
                        </Badge>
                    ))}
                </span>
            )}
        </Link>
    );
}

/**
 * The Save heart over a card's photo. It keeps what the last press made: the page asks again for its
 * facts only, which cover the first page's cards, so a card further down shows its own state.
 */
function CardHeart({ productId, name, saved }: { productId: string; name: string; saved: boolean }) {
    const t = useTranslator();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const [on, setOn] = useState(saved);
    const [busy, setBusy] = useState(false);

    useEffect(() => setOn(saved), [saved]);

    function press() {

        router.post(
            link(on ? 'storefront.feedback.remove' : 'storefront.feedback.save', { product: productId }),
            { back: window.location.pathname + window.location.search },
            {
                only: ['cardFacts'],
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    // A guest has gone to sign in; a customer's press is done.
                    if (shopper) {
                        setOn(!on);
                        window.dispatchEvent(new CustomEvent(HEART_PRESSED, { detail: { productId, saved: !on } }));
                    }
                },
            },
        );
    }

    return (
        <ActionButton
            type="button"
            size="icon-sm"
            variant="outline"
            loading={busy}
            aria-pressed={on}
            aria-label={on ? t('catalog::shop.card_save.remove', { name }) : t('catalog::shop.card_save.add', { name })}
            onClick={press}
            className="absolute end-5 top-5 rounded-full bg-surface/90"
            data-test="card-heart"
        >
            <Heart aria-hidden="true" className={on ? 'fill-red-500 text-red-500' : undefined} />
        </ActionButton>
    );
}
