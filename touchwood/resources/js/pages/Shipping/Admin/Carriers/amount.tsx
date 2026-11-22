import { useState, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText } from '@/components/ui/input-group';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { minorOf, typedOf } from '@/pages/Pricing/Admin/checks';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { CarrierData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';
import { LIST } from './parts';
import { P } from './prices';

/*
| The amounts of part 2 (shipping.md §4.1 part 2, R11, R12, R15): the price box and its checks, the carrier's
| Free Shipping card, and the store-wide amount's dialog. A module of its own, apart from prices.tsx: the
| carriers page loads it only when the store-wide amount is edited, so it stays within its budget (§5).
*/

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
 * The store-wide amount's dialog (R12): Platform's store setting `shipping.free.above_all`, in the smallest
 * unit, 0 = off - saved through Platform's own settings route, the amount typed in the store's currency.
 */
export function StoreFreeDialog({
    storeCode,
    storeFreeAbove,
    currency,
    decimals,
    onOpenChange,
}: {
    storeCode: string;
    storeFreeAbove: MoneyData | null;
    currency: string;
    decimals: number;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTranslator();
    const { errors } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(storeFreeAbove === null ? '' : typedOf(storeFreeAbove.minor, decimals));
    const [busy, setBusy] = useState(false);
    // 0 is how the setting says off: Turn Off says it, the box takes an amount (R12).
    const problem = amountProblem(typed, decimals, FREE_MAX, t) ?? (minorOf(typed, decimals) === 0 ? t(`${P}.store_free.zero`) : null);
    const save = (minor: number) =>
        router.post(
            `/admin/settings/shipping.free.above_all`,
            { store: storeCode, value: String(minor) },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
        );

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
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
    );
}
