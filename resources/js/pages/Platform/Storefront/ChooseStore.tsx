import { Link } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useTranslator } from '@/lib/t';
import type { ChooseStorePage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| F1 - choosing a country (frontend.md §3.6).
|
| The one page of the shop that belongs to no store: it is here because the visitor has not chosen
| one. The choice is remembered for a year, so somebody who has chosen never sees this page again -
| they are sent straight to their store, query string and all, so an advertisement's parameters
| survive the redirect (platform.md §3).
|
| Prices, stock and delivery all follow from this choice, which is why it is a page of its own
| rather than a dropdown in a corner.
*/

type Props = ChooseStorePage;

export default function ChooseStore({ stores }: Props) {
    const t = useTranslator();

    return (
        <StorefrontLayout title={t('platform::stores.choose_title')}>
            <div className="mx-auto grid max-w-2xl gap-6 py-10">
                <div className="grid gap-2 text-center">
                    <h1 className="text-2xl font-semibold text-ink">{t('platform::stores.choose_title')}</h1>
                    <p className="text-sm text-ink-muted">{t('platform::stores.choose_intro')}</p>
                </div>

                <ul className="grid gap-3 sm:grid-cols-3">
                    {stores.map((store) => (
                        <li key={store.code}>
                            <Link
                                href={store.href}
                                className="grid gap-1 rounded-lg border border-line bg-surface p-4 text-center shadow-card transition-colors hover:border-brand"
                            >
                                <span className="text-sm font-medium text-ink">{store.name}</span>
                                <span className="tw-figure text-xs text-ink-muted" dir="ltr">
                                    {store.currency} {store.symbol}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </StorefrontLayout>
    );
}
