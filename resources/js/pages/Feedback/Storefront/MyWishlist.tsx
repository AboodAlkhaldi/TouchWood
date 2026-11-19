import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { CardPriceLine, CardRating } from '@/components/shop/ProductCards';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { ActionButton } from '@/components/ActionButton';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { MyWishlistGroupData, MyWishlistPage, WishlistItemData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F8 - My Wishlist (feedback.md §4.1), grouped by store (Q20): each product a tile (flags-2026-11.md S2.2) with
| its price and stars, the whole tile linked in that store, Remove in its corner; one the person cannot order now
| marked "Not available now" (answer 17); a closed store's group marked too. Remove names the store the product was
| saved in, so it works in a closed one too.
*/

const W = 'feedback::shop';

export default function MyWishlist({ stores }: MyWishlistPage) {
    const t = useTranslator();

    return (
        <AccountLayout title={t(`${W}.my.wishlist.title`)} subtitle={t(`${W}.my.wishlist.subtitle`)} page="feedback.wishlist">
            <div className="grid min-w-0 content-start gap-4">
                {stores.length === 0 ? (
                    <Empty className="material-base" data-test="my-wishlist-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.my.wishlist.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    stores.map((group) => <StoreGroup key={group.storeCode} group={group} />)
                )}
            </div>
        </AccountLayout>
    );
}

function StoreGroup({ group }: { group: MyWishlistGroupData }) {
    const t = useTranslator();

    // Per store, a grid of tiles (flags-2026-11.md S2.2): 2 a row on a phone, 3 on a tablet, 4 from 1025 px.
    return (
        <section className="grid gap-3" data-test={`wishlist-store-${group.storeCode}`}>
            <header className="grid gap-0.5">
                <h2 className="text-heading-16 text-ink">{group.name}</h2>
                {group.isOpen ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.my.wishlist.store_off`)}</p>}
            </header>
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 min-[1025px]:grid-cols-4">
                {group.items.map((item) => (
                    <Tile key={item.product.id} item={item} storeId={group.storeId} card={group.cards[item.product.id] as ShopCardData | undefined} />
                ))}
            </ul>
        </section>
    );
}

/**
 * A saved product as a tile (flags-2026-11.md S2.2): its photo, name, price and stars as the shop's card shows them; the
 * whole tile opens the product (the name's link stretched over it), Remove in the photo's corner above it. One the person
 * cannot order now has no shop card: its photo and name, "Not available now", dimmed; Remove still works.
 */
function Tile({ item, storeId, card }: { item: WishlistItemData; storeId: string; card: ShopCardData | undefined }) {
    const t = useTranslator();
    const link = useLink();
    const [busy, setBusy] = useState(false);
    const product = item.product;

    return (
        <li className={`relative grid content-start gap-2 rounded-xl border border-line bg-surface p-2 transition-colors hover:border-line-strong ${card ? '' : 'opacity-70'}`} data-test="wishlist-item">
            <div className="aspect-square overflow-hidden rounded-lg bg-surface-sunken">
                {card ? (
                    <ShopPhoto photo={card.photo} size="card" alt="" className="size-full object-contain" />
                ) : product.photo ? (
                    <img src={product.photo} alt="" className="size-full object-cover" loading="lazy" />
                ) : null}
            </div>
            <div className="grid gap-1 px-1 pb-1">
                {product.href === null ? (
                    <span className="line-clamp-2 min-h-[2lh] text-label-14 text-ink">{product.name}</span>
                ) : (
                    <Link href={product.href} className="line-clamp-2 min-h-[2lh] text-label-14 text-ink after:absolute after:inset-0 after:rounded-xl hover:underline" data-test="wishlist-link">
                        {product.name}
                    </Link>
                )}
                {card ? (
                    <>
                        <CardPriceLine card={card} />
                        <CardRating card={card} />
                    </>
                ) : (
                    <Badge className={`justify-self-start ${tone('gray-subtle')}`}>{t(`${W}.my.not_available`)}</Badge>
                )}
            </div>
            <ActionButton
                type="button"
                size="icon"
                variant="outline"
                loading={busy}
                className="absolute end-3 top-3 z-10 size-9 rounded-full bg-surface/90"
                aria-label={t(`${W}.save.remove`, { name: product.name })}
                onClick={() => router.post(link('storefront.feedback.remove-saved'), { store: storeId, product: product.id }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                data-test="remove"
            >
                <X aria-hidden="true" />
            </ActionButton>
        </li>
    );
}
