import { useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Button } from '@/components/ui/button';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Time, useMoments } from '@/components/Time';
import { O, OrderStatus } from '@/components/sales/shop';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelectOption } from '@/components/ui/native-select';
import { AccountLayout } from '@/layouts/AccountLayout';
import { cn } from '@/lib/cn';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { OwnOrderPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { Bank } from './Checkout';

/*
| S8 - an order of the customer's (sales.md §4.1): the status they see and a timeline - Placed → Approved →
| Shipped → Delivered, or Cancelled / Refunded (R1-28); the payment in plain words, never "paid x of y" (R2-20,
| R3-1); the lines (the gift last at 0.00) and the totals without empty lines; the address and the way to pay,
| a bank transfer with the store's details and the receipt box while something is owed (§1.9); the shipment as
| text (R1-29); Cancel… with its reasons, out of reach once shipped with why (R1-30, R2-21); the returns and
| Ask for a Return… until the window closes (R2-22, R1-32); Buy Again (R1-33). No print, no download (R1-34).
*/

const CANCEL_REASONS = ['CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'FOUND_CHEAPER', 'TOO_SLOW', 'OTHER'];

export default function Order(order: OwnOrderPage) {
    const t = useTranslator();
    const link = useLink();
    const [cancelling, setCancelling] = useState(false);
    const [busy, setBusy] = useState(false);
    const shipped = order.shipment !== 'NOT_SHIPPED';
    const closed = order.status === 'CANCELLED' || order.status === 'REFUNDED';

    return (
        <AccountLayout title={t(`${O}.order.title`, { number: order.number })} page="sales.orders">
            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-4">
                <FormError />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                        <OrderStatus status={order.status} label={t(`${O}.status.${order.status}`)} />
                        <Time value={order.placedAt} />
                    </span>
                    <div className="flex flex-wrap gap-2">
                        <ActionButton
                            variant="outline"
                            loading={busy}
                            onClick={() => router.post(link('storefront.sales.order.buy-again', { number: order.number }), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                            data-test="buy-again"
                        >
                            {t(`${O}.order.buy_again`)}
                        </ActionButton>
                        {closed ? null : (
                            <ActionButton variant="outline" disabledReason={order.mayCancel ? undefined : shipped ? t(`${O}.order.cannot_cancel`) : t(`${O}.order.cannot_cancel_closed`)} onClick={() => setCancelling(true)} data-test="cancel-order">
                                {t(`${O}.order.cancel`)}
                            </ActionButton>
                        )}
                    </div>
                </div>

                <Timeline order={order} />

                <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid min-w-0 content-start gap-4">
                        <Lines order={order} />
                        <Returns order={order} />
                    </div>
                    <div className="grid min-w-0 content-start gap-4">
                        <Payment order={order} />
                        <Section title={t(`${O}.order.address`)} test="order-address">
                            <p className="text-copy-14 text-ink">
                                <bdi>{order.recipientName}</bdi>
                            </p>
                            {/* The number the courier calls: the account's phone as the order was placed (amendment 89). */}
                            <p className="text-copy-14 text-ink" data-test="order-phone">
                                {t(`${O}.order.account_phone`)}:{' '}
                                <bdi dir="ltr" className="tw-figure">
                                    {order.contactPhone}
                                </bdi>
                            </p>
                            <p className="text-copy-14 whitespace-pre-line text-ink-muted">{order.address}</p>
                        </Section>
                        <Shipment order={order} />
                    </div>
                </div>
            </div>
            {cancelling ? <CancelDialog order={order} onOpenChange={(open) => setCancelling(open)} /> : null}
        </AccountLayout>
    );
}

function Section({ title, test, children }: { title: string; test: string; children: ReactNode }) {
    return (
        <Card className="material-base gap-3 border-0 py-4" data-test={test}>
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-heading-16 text-ink">{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 px-4">{children}</CardContent>
        </Card>
    );
}

/** Placed → Approved → Shipped → Delivered, as far as the order has come; or where it ended (R1-28). */
function Timeline({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const reached = { NEW: 1, PROCESSING: 2, SHIPPED: 3, DELIVERED: 4, CANCELLED: 0, REFUNDED: 0 }[order.status] ?? 1;
    const steps: [string, string | null][] =
        order.status === 'CANCELLED' || order.status === 'REFUNDED'
            ? [
                  ['placed', order.placedAt],
                  [order.status === 'CANCELLED' ? 'cancelled' : 'refunded', null],
              ]
            : [
                  ['placed', order.placedAt],
                  ['approved', null],
                  ['shipped', order.shippedAt],
                  ['delivered', order.deliveredAt],
              ];
    const done = (index: number) => (reached === 0 ? true : index < reached);

    return (
        <ol className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center" aria-label={t(`${O}.timeline.label`)} data-test="timeline">
            {steps.map(([key, at], index) => (
                <li key={key} className={cn('flex items-center gap-2 text-copy-13', done(index) ? 'text-ink' : 'text-ink-muted')} aria-current={index === (reached === 0 ? steps.length - 1 : reached - 1) ? 'step' : undefined}>
                    <span className={cn('flex size-5 items-center justify-center rounded-full', done(index) ? 'bg-ink text-surface' : 'shadow-[inset_0_0_0_1px_var(--tw-line-strong)]')} aria-hidden="true">
                        {done(index) ? <Check className="size-3" /> : null}
                    </span>
                    <span>
                        {t(`${O}.timeline.${key}`)}
                        {at === null ? null : (
                            <span className="text-ink-muted">
                                {' · '}
                                <Time value={at} focusable={false} />
                            </span>
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}

function Lines({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const rows: [string, MoneyData | string | null, boolean][] = [
        [t(`${O}.order.subtotal`), order.subtotal, false],
        [t(`${O}.order.savings`), order.savings, true],
        [t(`${O}.order.coupon`, { code: order.couponCode ?? '' }), order.coupon, true],
        [t(`${O}.order.points`, { points: figure('en', order.pointsUsed) }), order.points, true],
        [t(`${O}.order.shipping`), order.shippingPending ? t(`${O}.order.shipping_pending`) : order.shipping, false],
        [t(`${O}.order.vat`), order.vat, false],
    ];

    return (
        <Section title={t(`${O}.order.lines`)} test="order-lines">
            <ul className="grid gap-2">
                {order.lines.map((line) => (
                    <li key={line.id} className="flex min-w-0 items-start justify-between gap-3 text-copy-14">
                        <span className="grid min-w-0 gap-0.5">
                            <span className="text-label-14 text-ink">
                                {line.name}
                                {line.gift ? <span className="text-copy-13 text-ink-muted"> · {t(`${O}.order.gift`)}</span> : null}
                            </span>
                            {line.values === '' ? null : <span className="text-copy-13 text-ink-muted">{line.values}</span>}
                            <span className="text-copy-13 text-ink-muted">
                                <bdi dir="ltr" className="tw-figure">
                                    {line.code}
                                </bdi>{' '}
                                {t(`${O}.order.quantity`, { count: figure('en', line.quantity) })} · <Money value={line.unit} />
                            </span>
                        </span>
                        <Money value={line.total} className="shrink-0 text-label-14 text-ink" />
                    </li>
                ))}
            </ul>
            <dl className="grid gap-1.5 border-t border-line pt-3 text-copy-14">
                {rows
                    .filter(([, value]) => value !== null)
                    .map(([label, value, minus]) => (
                        <div key={label} className="flex items-baseline justify-between gap-3">
                            <dt className="text-ink-muted">{label}</dt>
                            <dd className="text-ink">
                                {minus ? <span aria-hidden="true">− </span> : null}
                                {typeof value === 'string' ? value : <Money value={value as MoneyData} />}
                            </dd>
                        </div>
                    ))}
                <div className="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                    <dt className="text-label-14 text-ink">{order.shippingPending ? t(`${O}.order.total_before`) : t(`${O}.order.total`)}</dt>
                    <dd>
                        <Money value={order.total} className="text-label-14 text-ink" />
                    </dd>
                </div>
            </dl>
        </Section>
    );
}

/** The payment in plain words (R2-20, R3-1), the way to pay, and a transfer's details and receipts while something is owed. */
function Payment({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const link = useLink();
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const amount = order.payment === 'PARTLY_PAID' ? order.owed.text : order.payment === 'REFUND_DUE' ? order.refundDue.text : '';

    return (
        <Section title={t(`${O}.payment.title`)} test="order-payment">
            <p className="text-label-14 text-ink" data-test="payment-words">
                {t(`${O}.payment.${order.payment}`, { amount })}
            </p>
            <p className="text-copy-13 text-ink-muted">{t(`${O}.payment.${order.wayToPay}`)}</p>
            {order.bank !== null && order.mayUpload ? <Bank bank={order.bank} /> : null}
            {order.mayUpload ? (
                <form
                    className="grid gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (file === null) {
                            input.current?.focus();

                            return;
                        }

                        router.post(link('storefront.sales.receipt', { number: order.number }), { file } as never, {
                            forceFormData: true,
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => {
                                setFile(null);

                                if (input.current) input.current.value = '';
                            },
                        });
                    }}
                >
                    <Label htmlFor="order-receipt">{t('sales::shop_checkout.placed.file')}</Label>
                    <Input id="order-receipt" ref={input} type="file" accept="application/pdf,image/jpeg,image/png" onChange={(event) => setFile(event.target.files?.[0] ?? null)} data-test="receipt-file" />
                    <ActionButton type="submit" size="sm" className="w-fit" loading={busy} disabledReason={file === null ? t('sales::shop_checkout.placed.no_file') : undefined} data-test="upload-receipt">
                        {t('sales::shop_checkout.placed.send')}
                    </ActionButton>
                </form>
            ) : null}
        </Section>
    );
}

function Shipment({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const rows: [string, ReactNode][] = [];

    if (order.carrier !== null) rows.push([t(`${O}.order.carrier`), <bdi key="carrier">{order.carrier}</bdi>]);
    if (order.trackingNumber !== null)
        rows.push([
            t(`${O}.order.tracking`),
            <bdi key="tracking" dir="ltr" className="tw-figure">
                {order.trackingNumber}
            </bdi>,
        ]);
    if (order.shippedAt !== null) rows.push([t(`${O}.order.shipped_on`), <Time key="shipped" value={order.shippedAt} />]);
    if (order.deliveredAt !== null) rows.push([t(`${O}.order.delivered_on`), <Time key="delivered" value={order.deliveredAt} />]);

    return (
        <Section title={t(`${O}.order.shipment`)} test="order-shipment">
            {/* A carrier ordered with stays beside "Not shipped yet" (gap 6.7). */}
            {order.shipment === 'NOT_SHIPPED' || rows.length === 0 ? <p className="text-copy-14 text-ink-muted">{t(`${O}.order.not_shipped`)}</p> : null}
            {rows.length === 0 ? null : (
                <dl className="grid gap-1.5 text-copy-14">
                    {rows.map(([label, value]) => (
                        <div key={label} className="flex items-baseline justify-between gap-3">
                            <dt className="shrink-0 text-ink-muted">{label}</dt>
                            <dd className="min-w-0 text-end text-ink [overflow-wrap:anywhere]">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {/* Only with a link: the carrier's pattern with the number, or one staff typed (§9.5 #3). */}
            {order.trackingLink === null ? null : (
                <Button asChild variant="outline" className="w-fit">
                    <a href={order.trackingLink} target="_blank" rel="noopener noreferrer" data-test="track">
                        {t(`${O}.order.track`)}
                    </a>
                </Button>
            )}
        </Section>
    );
}

/** "You can ask for a return until 9 November" and Ask for a Return… (R2-22); the returns asked, each opening its page (R1-32). */
function Returns({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const link = useLink();
    const { date } = useMoments();

    if (order.returnDeadline === null && order.returns.length === 0) {
        return null;
    }

    return (
        <Section title={t(`${O}.returns.title`)} test="order-returns">
            {order.mayReturn && order.returnDeadline !== null ? (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-copy-14 text-ink">{t(`${O}.returns.until`, { date: date(order.returnDeadline) })}</p>
                    <Link href={link('storefront.sales.order.ask-return', { number: order.number })} className="text-copy-14 text-ink underline underline-offset-4" data-test="ask-return">
                        {t(`${O}.returns.ask`)}
                    </Link>
                </div>
            ) : null}
            {order.returns.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t(`${O}.returns.none`)}</p>
            ) : (
                <ul className="grid gap-2">
                    {order.returns.map((item) => (
                        <li key={item.id}>
                            <Link href={link('storefront.sales.return', { return: item.id })} className="material-small flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-copy-14" data-test={`order-return-${item.id}`}>
                                <span className="text-ink">{t(`${O}.returns.status.${item.status}`)}</span>
                                <span className="text-copy-13 text-ink-muted">
                                    {t(`${O}.returns.asked_on`)} <Time value={item.requestedAt} focusable={false} />
                                    {item.refundDue === null ? null : (
                                        <>
                                            {' · '}
                                            {t(`${O}.returns.refund`)} <Money value={item.refundDue} />
                                        </>
                                    )}
                                </span>
                                {item.staffReason === null ? null : (
                                    <span className="basis-full text-copy-13 text-ink-muted" data-test="return-store-reason">
                                        {t(`${O}.return.store_reason`)}: <bdi className="text-ink">{item.staffReason}</bdi>
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function CancelDialog({ order, onOpenChange }: { order: OwnOrderPage; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const link = useLink();
    const [reason, setReason] = useState('');
    const [other, setOther] = useState('');
    const [busy, setBusy] = useState(false);
    const paid = order.payment === 'PAID' || order.payment === 'PARTLY_PAID' || order.payment === 'REFUND_DUE';
    const missing = reason === '' ? t(`${O}.cancel.choose`) : reason === 'OTHER' && other.trim() === '' ? t(`${O}.cancel.other`) : undefined;

    return (
        <PanelDialog
            open
            destructive
            onOpenChange={onOpenChange}
            title={t(`${O}.cancel.title`)}
            description={t(`${O}.cancel.body`)}
            busy={busy}
            cancelLabel={t(`${O}.cancel.keep`)}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    disabledReason={missing}
                    onClick={() =>
                        router.post(link('storefront.sales.order.cancel', { number: order.number }), { reason, other: reason === 'OTHER' ? other : '' } as never, {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => onOpenChange(false),
                        })
                    }
                    data-test="confirm-cancel"
                >
                    {t(`${O}.cancel.confirm`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <SelectField id="cancel-reason" label={t(`${O}.cancel.reason`)} value={reason} onChange={(event) => setReason(event.target.value)} data-test="cancel-reason">
                    <NativeSelectOption value="">{t(`${O}.cancel.choose`)}</NativeSelectOption>
                    {CANCEL_REASONS.map((each) => (
                        <NativeSelectOption key={each} value={each}>
                            {t(`${O}.cancel.reasons.${each}`)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
                {reason === 'OTHER' ? <TextField id="cancel-other" label={t(`${O}.cancel.other`)} value={other} maxLength={500} onChange={(event) => setOther(event.target.value)} data-test="cancel-other" /> : null}
                {paid ? <p className="text-copy-13 text-ink-muted">{t(`${O}.cancel.paid`)}</p> : null}
            </div>
        </PanelDialog>
    );
}
