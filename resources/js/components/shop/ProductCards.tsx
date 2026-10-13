import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| The shop's product cards (catalog.md §4.5): photo, name and the labels the store attached, in the
| list's order (amendment 1(g)), each as Geist's Badge in its tone - and "Last pieces" while a size
| this viewer can buy and order now is ending soon (amendment 16(h)). No price, no stock, no Add to
| Cart, no code: those come with their stages.
*/

export function ProductCards({ cards, test = 'card' }: { cards: ShopCardData[]; test?: string }) {
    return (
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {cards.map((card) => (
                <li key={card.productId}>
                    <ProductCard card={card} test={test} />
                </li>
            ))}
        </ul>
    );
}

function ProductCard({ card, test }: { card: ShopCardData; test: string }) {
    const t = useTranslator();
    const link = useLink();

    return (
        <Link
            href={link('storefront.catalog.product', { slug: card.slug })}
            className="material-small group grid h-full content-start gap-3 overflow-hidden bg-surface p-3 hover:shadow-[0_0_0_1px_var(--tw-line-strong)]"
            data-test={test}
        >
            <ShopPhoto photo={card.photo} size="card" alt={card.name} className="aspect-square w-full rounded-md" />
            <span className="text-label-14 text-ink group-hover:underline">{card.name}</span>
            {card.labels.length === 0 && !card.lastPieces ? null : (
                <span className="flex flex-wrap gap-1">
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
