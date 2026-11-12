import { usePage } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { ShopCampaignPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| H7, a campaign's page while its run is live (content.md §4.5, Q5 (a)): its name and its end in the store's own time,
| then the products it reaches as the shop's cards for this shopper, 24 then Show More. The design has none: drawn from
| a category page's look (frontend.md §2.1; plan P4).
*/

export default function Campaign(props: ShopCampaignPageData) {
    const t = useTranslator();
    const page = usePage<SharedProps>();
    const { shop, locale } = page.props;
    const products = props.products as unknown as ShopCardData[];
    const facts = useCardFacts(props.cardFacts, products[0]?.productId);
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId, facts);
    const ends = new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'long', timeStyle: 'short', timeZone: shop?.timezone }).format(new Date(props.endsAt));
    // This page's own address, without its query: Show More asks it for the next page.
    const here = page.url.split('?')[0] ?? page.url;

    return (
        <StorefrontLayout title={props.head.title} address={props.address} head={props.head}>
            <div className="grid gap-6" data-test="campaign">
                <header className="grid gap-1">
                    <h1 className="text-heading-32 text-ink">{props.name}</h1>
                    <p className="text-copy-14 text-ink-muted" data-test="campaign-ends">
                        {t('content::shop.campaign.ends', { date: ends })}
                    </p>
                </header>
                {list.rows.length === 0 ? (
                    <Empty className="py-12">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('content::shop.campaign.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ProductCards facts={facts} cards={list.rows} />
                )}
                {props.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(here, { after: props.next ?? '' }, ['products', 'next', 'cardFacts'])} /> : null}
            </div>
        </StorefrontLayout>
    );
}
