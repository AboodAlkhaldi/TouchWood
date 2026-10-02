import { Link } from '@inertiajs/react';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useTranslator } from '@/lib/t';
import type { ChooseStorePage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| F1 - choosing a country (frontend.md §3.6), in Geist's type and materials (1.10).
|
| The one page of the shop that belongs to no store: it is here because the visitor has not chosen
| one. The choice is remembered for a year, so somebody who has chosen never sees this page again -
| they are sent straight to their store, query string and all, so an advertisement's parameters
| survive the redirect (platform.md §3).
|
| Prices, stock and delivery all follow from this choice, which is why it is a page of its own
| rather than a dropdown in a corner. Each country is a link, not a button: choosing one goes
| somewhere. Geist has no component for a tile that links, so each is built from its base material,
| outlined in brand under the pointer.
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

                <ul className="grid gap-3 sm:grid-cols-3">
                    {stores.map((store) => (
                        <li key={store.code}>
                            <Link
                                href={store.href}
                                className="material-base grid gap-1 p-4 text-center transition-shadow hover:shadow-[0_0_0_1px_var(--tw-brand)]"
                            >
                                <span className="text-label-14 font-medium text-ink">{store.name}</span>
                                <span className="tw-figure text-label-12 text-ink-muted" dir="ltr">
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
