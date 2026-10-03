import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { useTranslator } from '@/lib/t';
import type { StoreHomePage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| F2 - a store's home (frontend.md §3.6), as shadcn's Empty (Geist's Empty State: a space that is
| "temporarily empty").
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
            <Empty className="py-16">
                <EmptyHeader>
                    <EmptyTitle className="text-heading-24 text-ink">
                        <h1>{name}</h1>
                    </EmptyTitle>
                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::stores.placeholder')}</EmptyDescription>
                    {/* The sign beside the code, unless the sign *is* the code: a currency with no
                        sign of its own is shown as its letters (platform.md 1.2), and "EGP EGP"
                        reads as a mistake. */}
                    <p className="tw-figure text-copy-14 text-ink-muted" data-currency={currency}>
                        <bdi dir="ltr">{symbol === currency ? currency : `${currency} ${symbol}`}</bdi>
                    </p>
                </EmptyHeader>
            </Empty>
        </StorefrontLayout>
    );
}
