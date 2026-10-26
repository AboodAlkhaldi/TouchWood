import { Link, usePage } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { Button } from '@/components/ui/button';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopCardData, ShopSearchPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The shop's search results (catalog.md §4.5, P16): "N results for …", the cards 48 at a time with
| Show More; none found, a short line and the way to the categories. Only the brands shown in default
| listings, only what this viewer may see. The design has no results page: drawn from its look.
*/

export default function Search({ query, total, products, next, address, cardFacts }: ShopSearchPage) {
    const t = useTranslator();
    const link = useLink();
    const { frame } = usePage<SharedProps>().props;
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId);
    const facts = useCardFacts(cardFacts);
    const short = [...query.trim()].length < 2;
    const heading = short ? t('catalog::shop.search.title') : total === 1 ? t('catalog::shop.search.one', { query }) : t('catalog::shop.search.many', { count: total, query });

    return (
        <StorefrontLayout title={heading} address={address}>
            <div className="grid gap-6">
                <h1 className="text-heading-24 text-ink" data-test="search-heading">
                    {heading}
                </h1>

                {short ? (
                    <p className="text-copy-14 text-ink-muted">{t('catalog::shop.search.short')}</p>
                ) : list.rows.length === 0 ? (
                    <div className="grid gap-3" data-test="search-none">
                        <p className="text-copy-16 text-ink">{t('catalog::shop.search.none', { query })}</p>
                        <p className="text-copy-14 text-ink-muted">{t('catalog::shop.search.browse')}</p>
                        <nav className="flex flex-wrap gap-2">
                            {(frame?.catalog?.menu ?? []).map((top) => (
                                <Button key={top.id} variant="outline" size="sm" asChild>
                                    <Link href={link('storefront.catalog.category', { slug: top.slug })}>{top.name}</Link>
                                </Button>
                            ))}
                        </nav>
                    </div>
                ) : (
                    <ProductCards facts={facts} cards={list.rows} test="result" />
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(link('storefront.catalog.search'), { q: query, after: next }, ['products', 'next', 'cardFacts'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
