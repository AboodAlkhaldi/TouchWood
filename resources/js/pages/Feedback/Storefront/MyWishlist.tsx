import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AccountProduct } from '@/components/feedback/account';
import { ActionButton } from '@/components/ActionButton';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { MyWishlistGroupData, MyWishlistPage, WishlistItemData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F8 - My Wishlist (feedback.md §4.1), grouped by store (Q20): each product's card, linked in that store,
| with Remove; one the store no longer sells marked "Not available now" (answer 17); a closed store's
| group marked too. Remove names the store the product was saved in, so it works in a closed one too.
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

    return (
        <Card className="gap-3 py-4" data-test={`wishlist-store-${group.storeCode}`}>
            <CardHeader className="px-5">
                <CardTitle className="text-heading-16 text-ink">{group.name}</CardTitle>
                {group.isOpen ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.my.wishlist.store_off`)}</p>}
            </CardHeader>
            <CardContent className="px-5">
                <ul className="grid gap-3">
                    {group.items.map((item) => (
                        <Item key={item.product.id} item={item} storeId={group.storeId} />
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function Item({ item, storeId }: { item: WishlistItemData; storeId: string }) {
    const t = useTranslator();
    const link = useLink();
    const [busy, setBusy] = useState(false);

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3 last:border-b-0 last:pb-0" data-test="wishlist-item">
            <span className="flex flex-wrap items-center gap-2">
                <AccountProduct product={item.product} />
                {item.product.sold ? null : <Badge className={tone('gray-subtle')}>{t(`${W}.my.not_available`)}</Badge>}
            </span>
            <ActionButton
                type="button"
                size="sm"
                variant="outline"
                loading={busy}
                aria-label={t(`${W}.save.remove`, { name: item.product.name })}
                onClick={() => router.post(link('storefront.feedback.remove-saved'), { store: storeId, product: item.product.id }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                data-test="remove"
            >
                {t(`${W}.my.wishlist.remove`)}
            </ActionButton>
        </li>
    );
}
