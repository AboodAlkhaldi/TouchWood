import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { figure, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { minorOf, typedOf } from '@/pages/Pricing/Admin/checks';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { CarrierData, PricePlaceData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { LIST } from './parts';

/*
| What part 2 of the carriers screens shares (shipping.md §4.1 part 2; the owner, 2026-10-30): the amount
| box in the store's currency (R15), a place's chip (R16), the carrier's Free Shipping card (R11), the
| store-wide amount's card (R12) and the places no carrier serves (R13).
*/

export const P = 'shipping::admin_prices';

/** The most a price may be, and a free-above amount, in the smallest unit (§9.6 #5). */
export const PRICE_MAX = 100_000_000;
export const FREE_MAX = 1_000_000_000;

/** A typed amount's problem in words, or null when it is right (R15): the store's decimals, within the most. */
export function amountProblem(text: string, decimals: number, max: number, t: (key: string, values?: Record<string, string | number>) => string): string | null {
    if (text.trim() === '') {
        return null;
    }

    const minor = minorOf(text, decimals);

    if (minor === null) {
        return t(`${P}.dialog.amount_format`, { example: decimals <= 0 ? '20' : `20.${'0'.repeat(decimals)}` });
    }

    return minor > max ? t(`${P}.dialog.amount_max`, { max: typedOf(max, decimals) }) : null;
}

/** "Free" for 0, else the amount as the server wrote it ("20.00 ر.س"). */
export function AmountText({ amount }: { amount: MoneyData }) {
    const t = useTranslator();

    return <span className="tw-figure">{amount.minor === 0 ? t(`${P}.free`) : amount.text}</span>;
}

/** The price box (R15): the store's currency after it, left to right, Latin digits as typed. */
export function AmountBox({
    id,
    label,
    currency,
    value,
    onChange,
    error,
    helper,
}: {
    id: string;
    label: string;
    currency: string;
    value: string;
    onChange: (value: string) => void;
    error?: string | null;
    helper?: ReactNode;
}) {
    return (
        <div className="grid gap-1">
            <label htmlFor={id} className="text-label-14 text-ink">
                {label}
            </label>
            <InputGroup className="w-40">
                <InputGroupInput
                    id={id}
                    dir="ltr"
                    inputMode="decimal"
                    autoComplete="off"
                    className="tw-figure"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={[helper ? `${id}-helper` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined}
                    value={value}
                    onChange={(event) => onChange(toLatinDigits(event.target.value))}
                    data-test={id}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupText>{currency}</InputGroupText>
                </InputGroupAddon>
            </InputGroup>
            {helper ? (
                <p id={`${id}-helper`} className="text-copy-13 text-ink-muted">
                    {helper}
                </p>
            ) : null}
            {error ? (
                <FieldError id={`${id}-error`} className="text-copy-13">
                    {error}
                </FieldError>
            ) : null}
        </div>
    );
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

/**
 * The carrier's own free-above amount (R11): Save, or Clear - never free. While the store-wide amount is set
 * the card says it applies now, and the carrier's own is kept for when it is cleared.
 */
export function FreeShippingCard({
    carrier,
    storeCode,
    storeFreeAbove,
    mayManage,
    currency,
    decimals,
}: {
    carrier: CarrierData;
    storeCode: string;
    storeFreeAbove: MoneyData | null;
    mayManage: boolean;
    currency: string;
    decimals: number;
}) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(carrier.freeAbove === null ? '' : typedOf(carrier.freeAbove.minor, decimals));
    const [busy, setBusy] = useState(false);
    const problem = amountProblem(typed, decimals, FREE_MAX, t);
    const send = (clear: boolean) =>
        router.post(
            `${LIST}/${carrier.id}/free-above`,
            { store: storeCode, free_above: clear ? '' : typed, clear },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => (clear ? setTyped('') : undefined) },
        );

    return (
        <Card className="material-base" data-test="free-shipping">
            <CardHeader>
                <CardTitle className="text-heading-16 text-ink">{t(`${P}.freeship.title`)}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3">
                {storeFreeAbove !== null ? (
                    <p className="text-copy-14 text-ink-muted" data-test="store-free-overrides">
                        {t(`${P}.freeship.store_overrides`, { amount: storeFreeAbove.text })}
                    </p>
                ) : null}
                {mayManage ? (
                    <div className="flex flex-wrap items-end gap-2">
                        <AmountBox
                            id="carrier-free-above"
                            label={t(`${P}.freeship.free_above`)}
                            currency={currency}
                            value={typed}
                            onChange={setTyped}
                            error={problem ?? errors.free_above}
                            helper={t(`${P}.freeship.hint`)}
                        />
                        <ActionButton type="button" loading={busy} disabledReason={typed.trim() === '' || problem !== null ? (problem ?? t(`${P}.freeship.none`)) : undefined} onClick={() => send(false)} data-test="save-free-above">
                            {t(`${P}.freeship.save`)}
                        </ActionButton>
                        {carrier.freeAbove !== null ? (
                            <Button type="button" variant="outline" disabled={busy} onClick={() => send(true)} data-test="clear-free-above">
                                {t(`${P}.freeship.clear`)}
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <p className="text-copy-14" data-test="carrier-free-above">
                        {carrier.freeAbove !== null ? t(`${P}.freeship.row`, { amount: carrier.freeAbove.text }) : t(`${P}.freeship.none`)}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * The store-wide amount (R12): Platform's store setting `shipping.free.above_all`, in the smallest unit,
 * 0 = off - saved through Platform's own settings route, the amount typed in the store's currency.
 */
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
    const { errors } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');
    const [busy, setBusy] = useState(false);
    const problem = amountProblem(typed, decimals, FREE_MAX, t);
    const save = (minor: number) =>
        router.post(
            `/admin/settings/shipping.free.above_all`,
            { store: storeCode, value: String(minor) },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setOpen(false) },
        );

    return (
        <Card className="material-base" data-test="store-free">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle className="text-heading-16 text-ink">{t(`${P}.store_free.title`)}</CardTitle>
                {mayManage ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            setTyped(storeFreeAbove === null ? '' : typedOf(storeFreeAbove.minor, decimals));
                            setOpen(true);
                        }}
                        data-test="edit-store-free"
                    >
                        {t(`${P}.store_free.edit`)}
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent>
                <p className="text-copy-14" data-test="store-free-state">
                    {storeFreeAbove !== null ? t(`${P}.store_free.on`, { amount: storeFreeAbove.text }) : t(`${P}.store_free.off`)}
                </p>
            </CardContent>
            <PanelDialog
                open={open}
                onOpenChange={setOpen}
                title={t(`${P}.store_free.dialog_title`)}
                description={t(`${P}.store_free.dialog_body`)}
                busy={busy}
                confirm={
                    <div className="flex flex-wrap gap-2">
                        {storeFreeAbove !== null ? (
                            <Button type="button" variant="outline" disabled={busy} onClick={() => save(0)} data-test="turn-off-store-free">
                                {t(`${P}.store_free.turn_off`)}
                            </Button>
                        ) : null}
                        <ActionButton
                            loading={busy}
                            disabledReason={problem ?? (typed.trim() === '' ? t(`${P}.freeship.none`) : undefined)}
                            onClick={() => save(minorOf(typed, decimals) ?? 0)}
                            data-test="save-store-free"
                        >
                            {t(`${P}.store_free.save`)}
                        </ActionButton>
                    </div>
                }
            >
                <AmountBox id="store-free-above" label={t(`${P}.freeship.free_above`)} currency={currency} value={typed} onChange={setTyped} error={problem ?? errors.value} />
            </PanelDialog>
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
