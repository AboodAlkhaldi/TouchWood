import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import type { ChooseStorePage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| F1 - choosing a country (frontend.md §3.6), each country one of shadcn's linked Items (its
| `item-link` example, §1.11) with Geist's type.
|
| The one page of the shop that belongs to no store: it is here because the visitor has not chosen
| one. The choice is remembered for a year, so somebody who has chosen never sees this page again -
| they are sent straight to their store, query string and all, so an advertisement's parameters
| survive the redirect (platform.md §3).
|
| Prices, stock and delivery all follow from this choice, which is why it is a page of its own
| rather than a dropdown in a corner. Each country is a link, not a button: choosing one goes
| somewhere. The Item draws its own hover; nothing overrides the material's shadow (Geist's
| Materials).
*/

type Props = ChooseStorePage;

export default function ChooseStore({ stores }: Props) {
    const t = useTranslator();

    return (
        <StorefrontLayout title={t('platform::stores.choose_title')}>
            <div className="mx-auto grid max-w-2xl gap-6 py-10">
                <div className="grid gap-2 text-center">
                    <h1 className="text-heading-24 text-ink">{t('platform::stores.choose_title')}</h1>
                    <p className="text-copy-14 text-ink-muted">{t('platform::stores.choose_intro')}</p>
                </div>

                <ItemGroup className="grid gap-3 sm:grid-cols-3">
                    {stores.map((store) => (
                        <div key={store.code} role="listitem">
                            <Item variant="outline" asChild className="h-full bg-surface">
                                <Link href={store.href} data-test={`choose-${store.code}`}>
                                    <ItemContent>
                                        <ItemTitle className="text-label-14 font-medium text-ink">{store.name}</ItemTitle>
                                        <ItemDescription className="tw-figure text-label-12 text-ink-muted">
                                            <bdi dir="ltr">
                                                {store.currency} {store.symbol}
                                            </bdi>
                                        </ItemDescription>
                                    </ItemContent>
                                    <ItemActions>
                                        {/* Points the way the page reads: mirrored on an Arabic page. */}
                                        <ChevronRight aria-hidden="true" className="size-4 text-ink-subtle rtl:-scale-x-100" />
                                    </ItemActions>
                                </Link>
                            </Item>
                        </div>
                    ))}
                </ItemGroup>
            </div>
        </StorefrontLayout>
    );
}
