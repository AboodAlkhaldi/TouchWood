import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextareaField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { carrierLabel, useSend, W } from '@/components/sales/admin';
import { NativeSelectOption } from '@/components/ui/native-select';
import { isolate } from '@/lib/bidi';
import { intlLocale, toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { minorOf, typedOf } from '@/pages/Pricing/Admin/checks';
import type { OrderPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The order page's shipping dialogs (sales.md §4.3), loaded on first open so the page stays within its budget:
| Ship… and Correct Shipment… share one form - a carrier from the store's or "Other carrier", a tracking number
| and a link - and Set Shipping Fee… takes the fee and its reason, showing the new total as it is typed.
*/

const OTHER = 'OTHER';

/** The most a fee may be, in the smallest unit (SetShippingFeeHandler::MOST). */
const FEE_MOST = 100_000_000;

type Open = { open: boolean; onOpenChange: (open: boolean) => void };

export function ShipmentDialog({ open, onOpenChange, order, correcting }: Open & { order: OrderPage; correcting: boolean }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    // The carrier ordered first when shipping; the one it shipped with when correcting (E12). A carrier since deleted is not in the list.
    const first = correcting ? order.carrierId : order.orderedCarrierId;
    const known = order.carriers.some((carrier) => carrier.id === first);
    // "Other carrier", or a carrier since deleted: its name stays, typed.
    const stored = correcting && !known && order.carrier !== null;
    const [pick, setPick] = useState(known && first !== null ? first : stored ? OTHER : '');
    const [other, setOther] = useState(stored ? (order.carrier ?? '') : '');
    const [number, setNumber] = useState(correcting ? (order.trackingNumber ?? '') : '');
    const [link, setLink] = useState(correcting ? (order.trackingLink ?? '') : '');
    // A stored link can't be told apart as typed or made, and a typed link always wins (§1.5): changing what it was made from empties it.
    const [linkStored, setLinkStored] = useState(correcting && order.trackingLink !== null);
    const { busy, send } = useSend(`/admin/orders/${order.id}/${correcting ? 'shipment' : 'ship'}`, () => onOpenChange(false));
    const chosen = order.carriers.find((carrier) => carrier.id === pick);
    const checks = useChecks(
        [
            { id: 'carrier', label: t(`${W}.dialog.carrier`), value: pick, rules: { required: true } },
            { id: 'other_carrier', label: t(`${W}.dialog.other_name`), value: other, off: pick !== OTHER, rules: { required: true, length: { max: 100 } } },
            { id: 'tracking_number', label: t(`${W}.dialog.tracking`), value: number, rules: { length: { max: 100 } } },
            { id: 'tracking_link', label: t(`${W}.dialog.tracking_link`), value: link, rules: { length: { max: 500 }, format: { pattern: /^https:\/\/\S+$/i, key: `${W}.dialog.link_rule` } } },
        ],
        open,
    );
    const forgetStoredLink = () => {
        if (linkStored) {
            setLink('');
            setLinkStored(false);
        }
    };
    const title = correcting ? t(`${W}.dialog.correct_title`) : t(`${W}.dialog.ship_title`);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={correcting ? t(`${W}.dialog.correct_body`) : t(`${W}.dialog.ship_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => send({ ...(pick === OTHER ? { other_carrier: other } : { carrier_id: pick }), tracking_number: number, tracking_link: link }))}
                    data-test={correcting ? 'confirm-correct' : 'confirm-ship'}
                >
                    {title}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <SelectField
                    id="carrier"
                    label={t(`${W}.dialog.carrier`)}
                    value={pick}
                    check={checks.box('carrier')}
                    helper={correcting ? (first !== null && !known && order.carrier !== null ? t(`${W}.dialog.carrier_gone`, { name: isolate(order.carrier) }) : undefined) : !known && order.orderedCarrier !== null ? t(`${W}.dialog.ordered_with`, { name: isolate(order.orderedCarrier) }) : undefined}
                    onChange={(event) => {
                        setPick(event.target.value);
                        forgetStoredLink();
                    }}
                    data-test="carrier"
                >
                    <NativeSelectOption value="" disabled>
                        {t(`${W}.dialog.choose_carrier`)}
                    </NativeSelectOption>
                    {order.carriers.map((carrier) => (
                        <NativeSelectOption key={carrier.id} value={carrier.id}>
                            {carrierLabel(carrier, locale, t(`${W}.dialog.carrier_off`))}
                        </NativeSelectOption>
                    ))}
                    <NativeSelectOption value={OTHER}>{t(`${W}.dialog.other_carrier`)}</NativeSelectOption>
                </SelectField>
                {pick === OTHER ? (
                    <TextField
                        id="other_carrier"
                        label={t(`${W}.dialog.other_name`)}
                        value={other}
                        check={checks.box('other_carrier')}
                        onChange={(event) => {
                            setOther(event.target.value);
                            forgetStoredLink();
                        }}
                        data-test="other-carrier"
                    />
                ) : null}
                <TextField
                    id="tracking_number"
                    dir="ltr"
                    label={t(`${W}.dialog.tracking`)}
                    value={number}
                    check={checks.box('tracking_number')}
                    onChange={(event) => {
                        setNumber(toLatinDigits(event.target.value));
                        forgetStoredLink();
                    }}
                    data-test="tracking"
                />
                <TextField
                    id="tracking_link"
                    dir="ltr"
                    inputMode="url"
                    label={t(`${W}.dialog.tracking_link`)}
                    helper={chosen?.hasTrackingPattern === true ? t(`${W}.dialog.link_pattern`) : t(`${W}.dialog.link_none`)}
                    value={link}
                    check={checks.box('tracking_link')}
                    onChange={(event) => {
                        setLink(toLatinDigits(event.target.value));
                        setLinkStored(false);
                    }}
                    data-test="tracking-link"
                />
            </div>
        </PanelDialog>
    );
}

/** The total as it will read: the fee's change on what the order's total says now, shown only (the server works it out again). */
function totalWith(order: OrderPage, fee: number): string {
    const minor = order.total.minor - order.shipping.minor + fee;
    const figure = new Intl.NumberFormat(intlLocale('en'), { minimumFractionDigits: Math.max(0, order.exponent), maximumFractionDigits: Math.max(0, order.exponent) }).format(minor / 10 ** order.exponent);

    return order.total.text.replace(/[\d][\d,.]*/, figure);
}

export function FeeDialog({ open, onOpenChange, order }: Open & { order: OrderPage }) {
    const t = useTranslator();
    const [amount, setAmount] = useState(order.shippingFeePending ? '' : typedOf(order.shipping.minor, order.exponent));
    const [reason, setReason] = useState('');
    const { busy, send } = useSend(`/admin/orders/${order.id}/shipping-fee`, () => onOpenChange(false));
    const checks = useChecks(
        [
            { id: 'amount', label: t(`${W}.dialog.fee`), value: amount, rules: { required: true, number: { decimals: order.exponent, min: 0, max: FEE_MOST / 10 ** order.exponent } } },
            { id: 'reason', label: t(`${W}.dialog.reason`), value: reason, rules: { required: true, length: { max: 500 } } },
        ],
        open,
    );
    const minor = minorOf(amount, order.exponent);
    const title = order.shippingFeePending ? t(`${W}.dialog.fee_title`) : t(`${W}.dialog.fee_change_title`);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={t(`${W}.dialog.fee_body`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={checks.reason} onClick={() => checks.submit(() => send({ amount: String(minorOf(amount, order.exponent) ?? ''), reason }))} data-test="confirm-fee">
                    {title}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField
                    id="amount"
                    dir="ltr"
                    inputMode="decimal"
                    inputClassName="tw-figure"
                    label={t(`${W}.dialog.fee`)}
                    value={amount}
                    check={checks.box('amount')}
                    // The Arabic keyboard's digits and decimal mark are the same amount.
                    onChange={(event) => setAmount(toLatinDigits(event.target.value).replace('٫', '.'))}
                    data-test="fee"
                />
                {minor === null || minor > FEE_MOST ? null : (
                    <p className="flex items-baseline justify-between gap-3 text-copy-14 text-ink" data-test="fee-total">
                        <span className="text-ink-muted">{t(`${W}.dialog.fee_total`)}</span>
                        <span className="tw-figure text-label-14">
                            <bdi dir="ltr">{totalWith(order, minor)}</bdi>
                        </span>
                    </p>
                )}
                <TextareaField id="reason" rows={3} label={t(`${W}.dialog.reason`)} value={reason} check={checks.box('reason')} onChange={(event) => setReason(event.target.value)} data-test="reason" />
            </div>
        </PanelDialog>
    );
}
