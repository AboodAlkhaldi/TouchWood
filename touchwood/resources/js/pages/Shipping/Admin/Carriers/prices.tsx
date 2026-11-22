import { lazy, Suspense, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { PricePlaceData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

// The store-wide amount's dialog - its amount box and checks - loads when Edit is pressed (frontend.md §5).
const StoreFreeDialog = lazy(() => import('./amount').then((module) => ({ default: module.StoreFreeDialog })));

/*
| What part 2 of the carriers screens shares and the carriers page shows (shipping.md §4.1 part 2; the owner,
| 2026-10-30): an amount in words, a place's chip (R16) and a price's chips (R9), the store-wide amount's card
| (R12) and the places no carrier serves (R13). The amounts' boxes are in amount.tsx.
*/

export const P = 'shipping::admin_prices';

/** "Free" for 0, else the amount as the server wrote it ("20.00 ر.س"). */
export function AmountText({ amount }: { amount: MoneyData }) {
    const t = useTranslator();

    return <span className="tw-figure">{amount.minor === 0 ? t(`${P}.free`) : amount.text}</span>;
}

/** A place as a chip: its name, what it is under, Retired when it is (R16); ✕ to take it out when offered. */
export function PlaceChip({ place, onRemove }: { place: PricePlaceData; onRemove?: () => void }) {
    const t = useTranslator();

    return (
        <Badge className={`${tone(place.retired ? 'gray-subtle' : 'blue-subtle')} max-w-full gap-1`} data-test={`place-chip-${place.id}`}>
            <span className="truncate">
                {place.name}
                {place.under !== null ? <span className="text-ink-muted"> · {place.under}</span> : null}
            </span>
            {place.retired ? <span className="text-ink-muted">({t(`${P}.retired`)})</span> : null}
            {onRemove !== undefined ? (
                <button type="button" onClick={onRemove} aria-label={t(`${P}.remove`, { name: place.name })} className="ms-0.5 rounded-sm px-0.5 hover:bg-surface-sunken" data-test={`remove-place-${place.id}`}>
                    ✕
                </button>
            ) : null}
        </Badge>
    );
}

/** A price's places: the first 12 as chips and "+:count more", opening the rest (R9). */
export function PlaceChips({ places, first = 12 }: { places: PricePlaceData[]; first?: number }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [all, setAll] = useState(false);
    const shown = all ? places : places.slice(0, first);

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {shown.map((place) => (
                <PlaceChip key={place.id} place={place} />
            ))}
            {places.length > first ? (
                <Button type="button" variant="ghost" size="sm" onClick={() => setAll((open) => !open)} data-test="chips-more">
                    {all ? t(`${P}.fewer`) : t(`${P}.more`, { count: figure(locale, places.length - first) })}
                </Button>
            ) : null}
        </div>
    );
}

/** The store-wide amount (R12): on or off in words; Edit, for a price manager, opens its dialog. */
export function StoreFreeCard({
    storeCode,
    storeFreeAbove,
    mayManage,
    currency,
    decimals,
}: {
    storeCode: string;
    storeFreeAbove: MoneyData | null;
    mayManage: boolean;
    currency: string;
    decimals: number;
}) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);

    return (
        <Card className="material-base" data-test="store-free">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle className="text-heading-16 text-ink">{t(`${P}.store_free.title`)}</CardTitle>
                {mayManage ? (
                    <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)} data-test="edit-store-free">
                        {t(`${P}.store_free.edit`)}
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent>
                <p className="text-copy-14" data-test="store-free-state">
                    {storeFreeAbove !== null ? t(`${P}.store_free.on`, { amount: storeFreeAbove.text }) : t(`${P}.store_free.off`)}
                </p>
            </CardContent>
            {open ? (
                <Suspense fallback={null}>
                    <StoreFreeDialog storeCode={storeCode} storeFreeAbove={storeFreeAbove} currency={currency} decimals={decimals} onOpenChange={setOpen} />
                </Suspense>
            ) : null}
        </Card>
    );
}

/** A country's places no switched-on carrier serves, the biggest each time (R13): the first 10, then Show all. */
export function UncoveredPanel({ countryName, places }: { countryName: string; places: PricePlaceData[] }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [all, setAll] = useState(false);
    const shown = all ? places : places.slice(0, 10);

    if (places.length === 0) {
        return (
            <p className="text-copy-14 text-ink-muted" data-test="uncovered-none">
                {t(`${P}.uncovered.none`, { country: countryName })}
            </p>
        );
    }

    return (
        <div className="grid gap-2 rounded-md border border-line p-4" data-test="uncovered">
            <h3 className="flex items-center gap-2 text-label-14 text-ink">
                {t(`${P}.uncovered.title`)}
                <Badge className={tone('amber-subtle')} data-test="uncovered-count">
                    {figure(locale, places.length)}
                </Badge>
            </h3>
            <p className="text-copy-13 text-ink-muted">{t(`${P}.uncovered.body`)}</p>
            <ul className="flex flex-wrap gap-1.5">
                {shown.map((place) => (
                    <li key={place.id}>
                        <PlaceChip place={place} />
                    </li>
                ))}
            </ul>
            {places.length > 10 ? (
                <Button type="button" variant="ghost" size="sm" className="justify-self-start" onClick={() => setAll((open) => !open)} data-test="uncovered-all">
                    {all ? t(`${P}.uncovered.show_fewer`) : t(`${P}.uncovered.show_all`, { count: figure(locale, places.length) })}
                </Button>
            ) : null}
        </div>
    );
}
