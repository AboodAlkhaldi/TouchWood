import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextareaField, TextField } from '@/components/Fields';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelectOption } from '@/components/ui/native-select';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { OrderCarrierData, OrderLineData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| What the panel's order screens share (sales.md §4.1 P1–P3): the three states' badges, in the system's tones
| until the owner's one style for every badge (§9.2 #10), and the dialogs an order's page opens - each posting
| one of §3's commands and staying open on a refusal, which it says inside (PanelDialog).
*/

export const W = 'sales::admin_orders';

const STATUS: Record<string, Tone> = { PENDING_APPROVAL: 'amber-subtle', APPROVED: 'blue-subtle', COMPLETED: 'green-subtle', REJECTED: 'gray-subtle', CANCELLED: 'gray-subtle', UNDELIVERED: 'red-subtle' };
const SHIPMENT: Record<string, Tone> = { NOT_SHIPPED: 'gray-subtle', SHIPPED: 'blue-subtle', DELIVERED: 'green-subtle', CAME_BACK: 'red-subtle' };
const PAYMENT: Record<string, Tone> = { NOTHING_DUE: 'gray-subtle', UNPAID: 'red-subtle', PARTLY_PAID: 'amber-subtle', PAID: 'green-subtle', REFUND_DUE: 'amber-subtle', REFUNDED: 'gray-subtle' };

/** The order's, payment's and shipment's states, each a badge in its own words. */
export function StateBadges({ status, payment, shipment, short = false, fee }: { status: string; payment: string; shipment: string; short?: boolean; /** The fee to be set, in the page's words. */ fee?: string | null }) {
    const t = useTranslator();

    return (
        <span className="flex flex-wrap items-center gap-1">
            <Badge className={tone(STATUS[status] ?? 'gray-subtle')} data-test="order-status">
                {t(`${W}.status.${status}`)}
            </Badge>
            <Badge className={tone(PAYMENT[payment] ?? 'gray-subtle')} data-test="order-payment">
                {t(`${W}.payment.${payment}`)}
            </Badge>
            <Badge className={tone(SHIPMENT[shipment] ?? 'gray-subtle')} data-test="order-shipment">
                {t(`${W}.shipment.${shipment}`)}
            </Badge>
            {short ? (
                <Badge className={tone('red')} data-test="order-short">
                    {t(`${W}.orders.short`)}
                </Badge>
            ) : null}
            {fee === null || fee === undefined ? null : (
                <Badge className={tone('amber-subtle')} data-test="order-fee-pending">
                    {fee}
                </Badge>
            )}
        </span>
    );
}

/** A carrier's line in a list: another country's says which, a switched-off one says so (§4.3). */
export function carrierLabel(carrier: OrderCarrierData, locale: string, off: string): string {
    const parts = [carrier.name];

    if (!carrier.inOrderCountry) {
        parts.push(countryName(carrier.countryCode, locale));
    }

    if (!carrier.active) {
        parts.push(off);
    }

    return parts.join(' · ');
}

function countryName(code: string, locale: string): string {
    try {
        return new Intl.DisplayNames([intlLocale(locale)], { type: 'region' }).of(code) ?? code;
    } catch {
        return code;
    }
}

/** A form's own id, so pressing twice records once (sales.md §1.9): one per dialog opened. */
function uuid(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    // A page over plain HTTP has no randomUUID; getRandomValues is everywhere (RFC 9562's version 4).
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function useRequestId(open: boolean): string {
    const [id, setId] = useState(uuid);

    useEffect(() => {
        if (open) {
            setId(uuid());
        }
    }, [open]);

    return id;
}

export function useSend(url: string, onDone: () => void) {
    const [busy, setBusy] = useState(false);

    return {
        busy,
        send: (data: Record<string, unknown>) =>
            router.post(url, data as never, {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => onDone(),
            }),
    };
}

type Open = { open: boolean; onOpenChange: (open: boolean) => void };

/** Reject… and Cancel Order…: a reason of 1–500, required (§1.6). */
export function ReasonDialog({ open, onOpenChange, url, kind }: Open & { url: string; kind: 'reject' | 'cancel' }) {
    const t = useTranslator();
    const [reason, setReason] = useState('');
    const { busy, send } = useSend(url, () => onOpenChange(false));
    const checks = useChecks([{ id: 'reason', label: t(`${W}.dialog.reason`), value: reason, rules: { required: true, length: { max: 500 } } }], open);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${W}.dialog.${kind}_title`)}
            description={t(`${W}.dialog.${kind}_body`)}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} disabledReason={checks.reason} onClick={() => checks.submit(() => send({ reason }))} data-test={`confirm-${kind}`}>
                    {t(`${W}.dialog.${kind}_title`)}
                </ActionButton>
            }
        >
            <TextareaField id="reason" rows={3} label={t(`${W}.dialog.reason`)} value={reason} check={checks.box('reason')} onChange={(event) => setReason(event.target.value)} data-test="reason" />
        </PanelDialog>
    );
}

/** Came Back…: for each size and the gift, how many go back to stock - or lost for good, none (§1.5). */
export function CameBackDialog({ open, onOpenChange, url, lines }: Open & { url: string; lines: OrderLineData[] }) {
    const t = useTranslator();
    // A size's parts are put back together: one box per size and the gift.
    const sizes = useMemo(() => {
        const each = new Map<string, { variantId: string; gift: boolean; name: string; values: string; pieces: number }>();

        for (const line of lines) {
            const key = `${line.variantId}:${line.gift ? 'gift' : 'bought'}`;
            const found = each.get(key);
            each.set(key, found ? { ...found, pieces: found.pieces + line.quantity } : { variantId: line.variantId, gift: line.gift, name: line.name, values: line.values, pieces: line.quantity });
        }

        return [...each.entries()];
    }, [lines]);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [lost, setLost] = useState(false);
    const { busy, send } = useSend(url, () => onOpenChange(false));

    useEffect(() => {
        if (open) {
            setCounts(Object.fromEntries(sizes.map(([key, size]) => [key, size.pieces])));
            setLost(false);
        }
    }, [open, sizes]);

    const putBack = lost ? [] : sizes.map(([key, size]) => ({ variantId: size.variantId, gift: size.gift, quantity: counts[key] ?? 0 })).filter((line) => line.quantity > 0);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${W}.dialog.came_back_title`)}
            description={t(`${W}.dialog.came_back_body`)}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} onClick={() => send({ put_back: putBack })} data-test="confirm-came-back">
                    {t(`${W}.dialog.came_back_title`)}
                </ActionButton>
            }
        >
            <div className="grid gap-3">
                <div className="flex items-center gap-2">
                    <Checkbox id="came-back-lost" checked={lost} onCheckedChange={(on) => setLost(on === true)} data-test="lost" />
                    <Label htmlFor="came-back-lost" className="text-copy-14">
                        {t(`${W}.dialog.lost`)}
                    </Label>
                </div>
                {lost
                    ? null
                    : sizes.map(([key, size]) => (
                          <TextField
                              key={key}
                              id={`back-${key}`}
                              type="number"
                              inputMode="numeric"
                              min={0}
                              max={size.pieces}
                              inputClassName="tw-figure"
                              label={`${size.gift ? `${t(`${W}.show.gift`)}: ` : ''}${size.name}${size.values === '' ? '' : ` · ${size.values}`} — ${t(`${W}.dialog.back_to_stock`)}`}
                              value={String(counts[key] ?? 0)}
                              onChange={(event) => setCounts({ ...counts, [key]: Math.max(0, Math.min(size.pieces, Number(event.target.value) || 0)) })}
                              data-test={`back-${size.variantId}`}
                          />
                      ))}
            </div>
        </PanelDialog>
    );
}

const WAYS = ['BANK_TRANSFER', 'OTHER'] as const;

/** A day in the store's zone, as `happened_on` takes it (§1.9: never after today). */
function today(zone: string): string {
    const parts = new Intl.DateTimeFormat(intlLocale('en'), { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
    const part = (type: string) => parts.find((each) => each.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * Record Payment… and Record Refund… (§1.9). A payment is the whole amount still owed, shown and never typed
 * (the owner's R3-1, §4.2 B1); a refund any amount up to what the order holds.
 */
export function MoneyDialog({ open, onOpenChange, url, kind, amount, zone, exponent, initial = '', feePending = false }: Open & { url: string; kind: 'payment' | 'refund'; amount: MoneyData; zone: string; /** The order currency's decimals. */ exponent: number; /** A refund's amount to start with (a completed return's); a payment's, the owed amount shown, sent back to be checked. */ initial?: string; /** The fee is still to be set: a payment says it leaves it out (E14). */ feePending?: boolean }) {
    const t = useTranslator();
    const requestId = useRequestId(open);
    const [typed, setTyped] = useState('');
    const [way, setWay] = useState<(typeof WAYS)[number]>('BANK_TRANSFER');
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const [day, setDay] = useState(() => today(zone));
    const { busy, send } = useSend(url, () => onOpenChange(false));
    const refund = kind === 'refund';
    const checks = useChecks(
        [
            ...(refund ? [{ id: 'amount', label: t(`${W}.dialog.amount`), value: typed, rules: { required: true, number: { decimals: exponent } } }] : []),
            { id: 'reference', label: t(`${W}.dialog.reference`), value: reference, rules: { length: { max: 100 } } },
            { id: 'note', label: t(`${W}.dialog.note`), value: note, rules: { required: way === 'OTHER', length: { max: 500 } } },
            { id: 'happened_on', label: t(`${W}.dialog.happened_on`), value: day, rules: { required: true } },
        ],
        open,
    );

    useEffect(() => {
        if (open) {
            setTyped(initial);
            setWay('BANK_TRANSFER');
            setReference('');
            setNote('');
            setDay(today(zone));
        }
    }, [open, zone, initial]);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${W}.dialog.${kind}_title`)}
            description={refund ? t(`${W}.dialog.refund_body`, { amount: amount.text }) : t(`${W}.dialog.payment_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => send({ ...(refund ? { amount: typed } : { shown: initial }), way, reference, note, happened_on: day, request_id: requestId }))}
                    data-test={`confirm-${kind}`}
                >
                    {t(`${W}.dialog.${kind}_title`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {refund ? (
                    <TextField id="amount" dir="ltr" inputMode="decimal" inputClassName="tw-figure" label={t(`${W}.dialog.amount`)} value={typed} check={checks.box('amount')} onChange={(event) => setTyped(event.target.value)} data-test="amount" />
                ) : (
                    <p className="flex items-baseline justify-between gap-3 text-copy-14 text-ink" data-test="amount-owed">
                        <span className="text-ink-muted">{t(`${W}.dialog.amount`)}</span>
                        <Money value={amount} className="text-heading-20" />
                    </p>
                )}
                {!refund && feePending ? (
                    <p className="text-copy-13 text-ink-muted" data-test="payment-fee-note">
                        {t(`${W}.dialog.fee_payment`)}
                    </p>
                ) : null}
                <SelectField id="way" label={t(`${W}.dialog.way`)} value={way} onChange={(event) => setWay(event.target.value as (typeof WAYS)[number])} data-test="way">
                    {WAYS.map((each) => (
                        <NativeSelectOption key={each} value={each}>
                            {t(`${W}.money_way.${each}`)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
                <TextField id="reference" dir="ltr" label={t(`${W}.dialog.reference`)} value={reference} check={checks.box('reference')} onChange={(event) => setReference(event.target.value)} data-test="reference" />
                <TextareaField id="note" rows={2} label={way === 'OTHER' ? t(`${W}.dialog.note_other`) : t(`${W}.dialog.note`)} value={note} check={checks.box('note')} onChange={(event) => setNote(event.target.value)} data-test="note" />
                <TextField id="happened_on" type="date" max={today(zone)} label={refund ? t(`${W}.dialog.refunded_on`) : t(`${W}.dialog.happened_on`)} value={day} check={checks.box('happened_on')} onChange={(event) => setDay(event.target.value)} data-test="happened-on" />
            </div>
        </PanelDialog>
    );
}

/** Void…: a record entered by mistake no longer counts, with why (§1.9). */
export function VoidDialog({ open, onOpenChange, url, kind, record }: Open & { url: string; kind: string; record: string }) {
    const t = useTranslator();
    const [reason, setReason] = useState('');
    const { busy, send } = useSend(url, () => onOpenChange(false));
    const checks = useChecks([{ id: 'void-reason', label: t(`${W}.dialog.reason`), value: reason, rules: { required: true, length: { max: 500 } } }], open);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            title={t(`${W}.dialog.void_title`)}
            description={t(`${W}.dialog.void_body`)}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} disabledReason={checks.reason} onClick={() => checks.submit(() => send({ kind, record, reason }))} data-test="confirm-void">
                    {t(`${W}.dialog.void_title`)}
                </ActionButton>
            }
        >
            <TextareaField id="void-reason" rows={2} label={t(`${W}.dialog.reason`)} value={reason} check={checks.box('void-reason')} onChange={(event) => setReason(event.target.value)} data-test="void-reason" />
        </PanelDialog>
    );
}
