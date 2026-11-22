import { useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Button } from '@/components/ui/button';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Time, useMoments } from '@/components/Time';
import { useClock } from '@/components/sales/payment/flow';
import { O, OrderStatus, plural, useLocale } from '@/components/sales/shop';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelectOption } from '@/components/ui/native-select';
import { AccountLayout } from '@/layouts/AccountLayout';
import { isolate } from '@/lib/bidi';
import { cn } from '@/lib/cn';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { OwnOrderPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { Bank } from './Checkout';

/*
| S8 - an order of the customer's (sales.md §4.1): the status they see and a timeline - Placed → Approved →
| Shipped → Delivered, or Cancelled / Refunded (R1-28); the payment in plain words, never "paid x of y" (R2-20,
| R3-1); the lines (the gift last at 0.00) and the totals without empty lines; the address and the way to pay,
| a bank transfer with the store's details and the receipt box while something is owed (§1.9); the shipment as
| text (R1-29); Cancel… with its reasons, out of reach once shipped with why (R1-30, R2-21); the returns and
| Ask for a Return… until the window closes (R2-22, R1-32); Buy Again (R1-33). No print, no download (R1-34).
|
| To the design (shop-design.md §15.2): the status as a 4-step stepper with a time under each (Approved's the decision's);
| the lines as one bordered list - a 64 px photo, the name, chips for the code, each value and Retail or Wholesale, the
| line's total with its unit price and unit of sale; the address with the line that it is kept as a copy; and a 372 px
| side column that stays in place: the shipment (Track only with a link), the payment, the order's points, then Cancel
| with its note.
*/

const CANCEL_REASONS = ['CHANGED_MIND', 'ORDERED_BY_MISTAKE', 'FOUND_CHEAPER', 'TOO_SLOW', 'OTHER'];

export default function Order(order: OwnOrderPage) {
    const t = useTranslator();
    const link = useLink();
    const clock = useClock();
    const [cancelling, setCancelling] = useState(false);
    const [busy, setBusy] = useState(false);
    const shipped = order.shipment !== 'NOT_SHIPPED';
    const closed = order.status === 'CANCELLED' || order.status === 'REFUNDED';

    return (
        <AccountLayout title={t(`${O}.order.title`, { number: order.number })} page="sales.orders">
            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start gap-4">
                <FormError />
                {order.isTest ? <Note variant="warning" data-test="order-test-note">{t(`${O}.test_note`)}</Note> : null}
                {order.paymentBeingConfirmed ? (
                    <Note data-test="order-confirming">{t(`${O}.order.confirming`)}</Note>
                ) : order.paymentRequest === null ? null : (
                    <Note data-test="order-request">
                        <span className="flex flex-wrap items-center justify-between gap-2">
                            {t(`${O}.order.request`, { amount: isolate(order.paymentRequest.amount.text), time: isolate(clock(order.paymentRequest.expiresAt)) })}
                            <Button asChild size="sm">
                                <Link href={link('storefront.sales.request', { number: order.number })} data-test="order-pay-now">
                                    {t(`${O}.order.pay_now`)}
                                </Link>
                            </Button>
                        </span>
                    </Note>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <span className="flex flex-wrap items-center gap-2 text-copy-14 text-ink-muted">
                        <OrderStatus status={order.status} label={t(`${O}.status.${order.status}`)} />
                        <Time value={order.placedAt} />
                    </span>
                    <ActionButton
                        variant="outline"
                        loading={busy}
                        onClick={() => router.post(link('storefront.sales.order.buy-again', { number: order.number }), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                        data-test="buy-again"
                    >
                        {t(`${O}.order.buy_again`)}
                    </ActionButton>
                </div>

                <Timeline order={order} />

                <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,1fr)_372px] lg:gap-6">
                    <div className="grid min-w-0 content-start gap-4">
                        <Lines order={order} />
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
                            <p className="border-t border-line pt-3 text-copy-13 text-ink-muted" data-test="address-copy">
                                {t(`${O}.order.address_copy`)}
                            </p>
                        </Section>
                        <Returns order={order} />
                    </div>
                    {/* The side column, staying in place while the main one scrolls (§15.2). */}
                    <div className="grid min-w-0 content-start gap-4 lg:sticky lg:top-36 lg:self-start" data-test="order-side">
                        <Shipment order={order} />
                        <Payment order={order} />
                        <Points order={order} />
                        {closed ? null : (
                            <div className="grid gap-2" data-test="order-cancel">
                                <ActionButton variant="outline" className="h-11 w-full border-bad/40 text-bad hover:text-bad" disabledReason={order.mayCancel ? undefined : order.paymentBeingConfirmed ? t(`${O}.order.confirming`) : shipped ? t(`${O}.order.cannot_cancel`) : t(`${O}.order.cannot_cancel_closed`)} onClick={() => setCancelling(true)} data-test="cancel-order">
                                    {t(`${O}.order.cancel`)}
                                </ActionButton>
                                <p className="text-copy-13 text-ink-muted">{shipped ? t(`${O}.order.cannot_cancel`) : t(`${O}.order.cancel_note`)}</p>
                            </div>
                        )}
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

/**
 * Placed → Approved → Shipped → Delivered as a 4-step stepper, each with its time - Approved's the decision's (§15.2); or
 * where it ended (R1-28).
 */
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
                  ['approved', order.approvedAt ?? null],
                  ['shipped', order.shippedAt],
                  ['delivered', order.deliveredAt],
              ];
    const done = (index: number) => (reached === 0 ? true : index < reached);

    return (
        <ol className={cn('grid gap-3 rounded-xl border border-line bg-surface p-4', steps.length === 4 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-2')} aria-label={t(`${O}.timeline.label`)} data-test="timeline">
            {steps.map(([key, at], index) => (
                <li key={key} className="relative grid justify-items-start gap-2" aria-current={index === (reached === 0 ? steps.length - 1 : reached - 1) ? 'step' : undefined}>
                    <span className="flex w-full items-center gap-2" aria-hidden="true">
                        <span className={cn('tw-figure flex size-8 shrink-0 items-center justify-center rounded-full text-label-13 font-semibold', done(index) ? 'bg-brand text-ink-on-brand' : 'bg-surface-sunken text-ink-muted')}>
                            {done(index) ? <Check className="size-4" /> : index + 1}
                        </span>
                        {index < steps.length - 1 ? <i className={cn('h-0.5 flex-1 rounded-full max-sm:hidden', done(index + 1) ? 'bg-brand' : 'bg-line')} /> : null}
                    </span>
                    <span className={cn('grid gap-0.5 text-copy-13', done(index) ? 'text-ink' : 'text-ink-muted')}>
                        <span className="text-label-14">{t(`${O}.timeline.${key}`)}</span>
                        <span className="text-ink-muted">{at === null ? '—' : <Time value={at} focusable={false} />}</span>
                    </span>
                </li>
            ))}
        </ol>
    );
}

function Lines({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const rows: [string, MoneyData | string | null, boolean][] = [
        [t(`${O}.order.subtotal`), order.subtotal, false],
        [t(`${O}.order.savings`), order.savings, true],
        [t(`${O}.order.coupon`, { code: order.couponCode ?? '' }), order.coupon, true],
        [t(`${O}.order.points`, { points: figure('en', order.pointsUsed) }), order.points, true],
        [t(`${O}.order.shipping`), order.shippingPending ? t(`${O}.order.shipping_pending`) : order.shipping, false],
        [t(`${O}.order.vat`), order.vat, false],
    ];
    const sold = order.lines.filter((line) => !line.gift);
    const pieces = sold.reduce((sum, line) => sum + line.quantity, 0);

    return (
        <section className="grid gap-0 overflow-hidden rounded-xl border border-line bg-surface" aria-labelledby="order-lines-title" data-test="order-lines">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-4 py-3">
                <h2 id="order-lines-title" className="text-heading-16 text-ink">
                    {t(`${O}.order.lines`)}
                </h2>
                <span className="tw-figure text-copy-13 text-ink-muted" data-test="order-lines-count">
                    {plural(t, locale, `${O}.order.items`, sold.length)} · {plural(t, locale, `${O}.order.pieces`, pieces)}
                </span>
            </div>
            <ul className="grid divide-y divide-line">
                {order.lines.map((line) => (
                    <li key={line.id} className="grid grid-cols-[4rem_minmax(0,1fr)_auto] items-start gap-3 px-4 py-3 text-copy-14" data-test="order-line">
                        <ShopPhoto photo={(line.photo ?? null) as PhotoSizes | null} size="thumb" alt="" className="size-16 rounded-lg bg-surface-sunken object-contain" />
                        <span className="grid min-w-0 gap-1">
                            <span className="text-label-14 text-ink">
                                {line.name}
                                {line.gift ? <span className="text-copy-13 text-ink-muted"> · {t(`${O}.order.gift`)}</span> : null}
                            </span>
                            {/* Chips for the code, each value and the way of buying (§15.2). */}
                            <span className="flex flex-wrap items-center gap-1.5" data-test="order-line-chips">
                                <bdi dir="ltr" className="tw-figure rounded-full bg-surface-sunken px-2.5 py-0.5 text-label-12 text-ink">
                                    {line.code}
                                </bdi>
                                {((line.chosen as string[] | undefined) ?? []).map((value) => (
                                    <span key={value} className="rounded-full bg-surface-sunken px-2.5 py-0.5 text-label-12 text-ink">
                                        {value}
                                    </span>
                                ))}
                                <span className={cn('rounded-full px-2.5 py-0.5 text-label-12', line.mode === 'WHOLESALE' ? 'bg-accent/10 text-accent' : 'bg-brand/10 text-brand')} data-test="order-line-mode">
                                    {t(`${O}.order.mode.${line.mode}`)}
                                </span>
                            </span>
                        </span>
                        <span className="grid justify-items-end gap-0.5 text-end">
                            <Money value={line.total} className="text-label-14 font-semibold text-ink" />
                            <span className="text-copy-13 text-ink-muted">
                                <Money value={line.unit} /> {t(`${O}.order.quantity`, { count: figure('en', line.quantity) })} · {t(`catalog::shop.unit.${line.saleUnit}`)}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
            <dl className="grid gap-1.5 border-t border-line px-4 py-3 text-copy-14">
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
                        <Money value={order.total} className="text-label-16 font-semibold text-brand" />
                    </dd>
                </div>
            </dl>
        </section>
    );
}

/** The points this order earns, added at delivery and taken back on a cancel or a return (§15.2). */
function Points({ order }: { order: OwnOrderPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const earned = order.pointsEarned ?? 0;

    // Before delivery only: once delivered, the points were added with that day's settings, which this figure (worked out
    // now) may not match - the balance shows what was added.
    if (earned <= 0 || order.status === 'DELIVERED') {
        return null;
    }

    return (
        <Section title={t(`${O}.order.points_title`)} test="order-points">
            <p className="tw-figure text-heading-20 text-ink">{plural(t, locale, `${O}.order.points_count`, earned)}</p>
            <p className="text-copy-13 text-ink-muted">{t(`${O}.order.points_later`)}</p>
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
