import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useTranslator } from '@/lib/t';
import type { StoreHomePage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| F2 - a store's home (frontend.md §3.6).
|
| A placeholder until Content builds the real one (platform.md §3). It says which store somebody is
| in and what it charges in, and that is the whole of what this stage promises - the shop's real
| front page needs a catalogue, and there is not one yet.
*/

type Props = StoreHomePage;

export default function Home({ name, currency, symbol }: Props) {
    const t = useTranslator();

    return (
        <StorefrontLayout title={name}>
            <div className="mx-auto grid max-w-2xl gap-3 py-16 text-center">
                <h1 className="text-2xl font-semibold text-ink">{name}</h1>
                <p className="text-sm text-ink-muted">{t('platform::stores.placeholder')}</p>
                {/* The sign beside the code, unless the sign *is* the code: a currency with no
                    sign of its own is shown as its letters (platform.md 1.2), and "EGP EGP" reads
                    as a mistake. */}
                <p className="tw-figure text-sm text-ink-muted" dir="ltr" data-currency={currency}>
                    {symbol === currency ? currency : `${currency} ${symbol}`}
                </p>
            </div>
        </StorefrontLayout>
    );
}
