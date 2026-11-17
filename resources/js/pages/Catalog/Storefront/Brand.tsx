import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards, ProductRows, useCardFacts, useShopView, ViewSwitch } from '@/components/shop/ProductCards';
import { RichText } from '@/components/shop/RichText';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { ListingSlots, type ListingSlotsData } from '@/components/content/ListingSlot';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopBrandPage, ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| A brand's page in the shop (catalog.md §4.5): its logo, name and description, then its products as a
| category page shows them, 24 at a time with Show More. A secondary brand keeps its page (§1.6). The
| design has no brand page: drawn in the category page's style (shop-design.md §5.1) - the 34 px name, the
| grid or the technical list, the shop's Show More, the phone's compact top bar.
*/

export default function Brand({ name, slug, logo, description, products, next, address, cardFacts, soldOut, slots }: ShopBrandPage & { slots?: ListingSlotsData }) {
    const t = useTranslator();
    const link = useLink();
    const facts = useCardFacts(cardFacts, products[0]?.productId);
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId, facts);
    const [view, setView] = useShopView();

    return (
        <StorefrontLayout title={name} address={address} compact={{ back: link('storefront.home') }}>
            <div className="grid gap-5 sm:gap-6">
                <div className="max-sm:hidden">
                    <ShopTrail path={[]} current={name} />
                </div>
                <header className="grid gap-3 border-b border-line pb-5" data-test="brand-head">
                    <div className="flex flex-wrap items-center gap-4">
                        {logo !== null ? <ShopPhoto photo={logo} size="thumb" alt="" className="size-16 rounded-md bg-surface object-contain" /> : null}
                        <h1 className="text-[26px] leading-tight font-semibold text-ink sm:text-[34px]">{name}</h1>
                    </div>
                    <RichText document={description} className="max-w-3xl" />
                </header>
                {list.rows.length > 0 ? (
                    <div className="hidden justify-end md:flex">
                        <ViewSwitch view={view} onChange={setView} />
                    </div>
                ) : null}
                <ListingSlots slots={slots} />

                {list.rows.length === 0 ? (
                    <Empty className="py-12">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(soldOut ? 'catalog::shop.nothing_now' : 'catalog::shop.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : view === 'list' ? (
                    <>
                        <div className="hidden md:block">
                            <ProductRows cards={list.rows} />
                        </div>
                        <div className="md:hidden">
                            <ProductCards facts={facts} cards={list.rows} />
                        </div>
                    </>
                ) : (
                    <ProductCards facts={facts} cards={list.rows} />
                )}

                {next !== null ? <LoadMoreButton shop loading={list.loading} onClick={() => list.more(link('storefront.catalog.brand', { slug }), { after: next }, ['products', 'next', 'cardFacts'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
