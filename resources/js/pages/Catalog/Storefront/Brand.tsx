import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards } from '@/components/shop/ProductCards';
import { RichText } from '@/components/shop/RichText';
import { ShopPhoto } from '@/components/shop/ShopPhoto';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopBrandPage, ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| A brand's page in the shop (catalog.md §4.5): its logo, name and description, then its products as a
| category page shows them, 24 at a time with Show More. A secondary brand keeps its page (§1.6). The
| design has no brand page: drawn from its look (frontend.md §2.1).
*/

export default function Brand({ name, slug, logo, description, products, next, address }: ShopBrandPage) {
    const t = useTranslator();
    const link = useLink();
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId);

    return (
        <StorefrontLayout title={name} address={address}>
            <div className="grid gap-6">
                <ShopTrail path={[]} current={name} />
                <header className="flex flex-wrap items-center gap-4">
                    {logo !== null ? <ShopPhoto photo={logo} size="thumb" alt="" className="size-16 rounded-md bg-surface object-contain" /> : null}
                    <h1 className="text-heading-32 text-ink">{name}</h1>
                </header>
                <RichText document={description} className="max-w-3xl" />

                {list.rows.length === 0 ? (
                    <Empty className="py-12">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('catalog::shop.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ProductCards cards={list.rows} />
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(link('storefront.catalog.brand', { slug }), { after: next }, ['products', 'next'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
