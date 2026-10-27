import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { AddressLines } from '@/components/AddressLines';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { CameBackDialog, MoneyDialog, ReasonDialog, ShipDialog, StateBadges, VoidDialog, W } from '@/components/sales/admin';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure, intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { MoneyRecordData, OrderPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { MoreHorizontal } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/page';
import { CustomerName } from './Index';

/*
| P2 - an order in the panel (sales.md §4.1): one page - the header with its four states and the next step as
| the main button (the owner's R2-26), the rest in a ⋯ menu; the short-of-stock banner (R2-32); the customer
| and the contact - the phone as text with Copy and WhatsApp (R1-40), the email only to staff who may view the
| customer (answer 47) - the lines, the totals, the money, the shipment, the receipts, the returns, the edits
| and the history in plain sentences (R2-28). A button out of reach says why; a job not held shows none.
*/

type Dialog = 'reject' | 'cancel' | 'ship' | 'came_back' | 'payment' | 'refund' | { void: MoneyRecordData } | null;

const CLOSED = ['REJECTED', 'CANCELLED', 'COMPLETED', 'UNDELIVERED'];

export default function Show(order: OrderPage) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [dialog, setDialog] = useState<Dialog>(null);
    const [busy, setBusy] = useState(false);
    const base = `/admin/orders/${order.id}`;
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const post = (action: string, data: Record<string, unknown> = {}) => router.post(`${base}/${action}`, data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const waiting = order.status === 'PENDING_APPROVAL';
    const approved = order.status === 'APPROVED';
    const notShipped = order.shipment === 'NOT_SHIPPED';
    const shipped = order.shipment === 'SHIPPED';
    const a = order.actions;
    // Covered as the server has it: nothing still owed (sales.md §1.4).
    const unpaidToShip = order.owed.minor > 0 && !order.mayShipUnpaid;

    // The next step, by the order's state (R2-26): what staff most often do here.
    const main =
        waiting && a.mayApprove ? (
            <ActionButton loading={busy} onClick={() => post('approve')} data-test="approve">
                {t(`${W}.action.approve`)}
            </ActionButton>
        ) : approved && notShipped && a.mayFulfil ? (
            <ActionButton disabledReason={unpaidToShip ? t(`${W}.why.unpaid`) : undefined} onClick={() => setDialog('ship')} data-test="ship">
                {t(`${W}.action.ship`)}
            </ActionButton>
        ) : shipped && a.mayFulfil ? (
            <ActionButton loading={busy} onClick={() => post('deliver')} data-test="deliver">
                {t(`${W}.action.deliver`)}
            </ActionButton>
        ) : null;

    const more = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="icon" aria-label={t(`${W}.show.actions`)} data-test="order-actions">
                    <MoreHorizontal aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-60">
                {a.mayApprove ? <MenuAction label={t(`${W}.action.reject`)} reason={waiting ? null : t(`${W}.why.not_waiting`)} onSelect={() => setDialog('reject')} test="reject" /> : null}
                {a.mayCancel ? (
                    <MenuAction
                        destructive
                        label={t(`${W}.action.cancel`)}
                        reason={CLOSED.includes(order.status) ? t(`${W}.why.closed`) : notShipped ? null : t(`${W}.why.shipped`)}
                        onSelect={() => setDialog('cancel')}
                        test="cancel"
                    />
                ) : null}
                {approved && notShipped && a.mayFulfil && (order.owed.minor > 0 || order.mayShipUnpaid) ? (
                    <DropdownMenuItem onSelect={() => post('ship-unpaid', { allow: !order.mayShipUnpaid })} data-test="ship-unpaid">
                        {order.mayShipUnpaid ? t(`${W}.action.ship_unpaid_off`) : t(`${W}.action.ship_unpaid_on`)}
                    </DropdownMenuItem>
                ) : null}
                {a.mayFulfil ? <MenuAction destructive label={t(`${W}.action.came_back`)} reason={shipped ? null : t(`${W}.why.not_shipped`)} onSelect={() => setDialog('came_back')} test="came-back" /> : null}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={`${base}/print`} data-test="print">
                        {t(`${W}.action.print`)}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <AdminLayout
            title={order.number}
            subtitle={t(`${W}.show.placed`, { when: new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeStyle: 'short', timeZone: order.storeTimezone }).format(new Date(order.placedAt)) })}
            breadcrumbs={[{ label: t(`${W}.orders.title`), href: '/admin/orders' }]}
            action={
                <span className="flex items-center gap-2">
                    {main}
                    {more}
                </span>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <div className="flex flex-wrap items-center gap-2">
                    <StateBadges status={order.status} payment={order.payment} shipment={order.shipment} />
                    {order.store.isActive ? null : <Badge className={tone('gray')}>{t(`${W}.off`)}</Badge>}
                    <span className="text-copy-13 text-ink-muted">{order.store.name}</span>
                </div>

                {order.shortOfStockAt !== null ? (
                    <Note variant="warning" label={t(`${W}.orders.short`)} data-test="short-banner">
                        <span className="flex flex-wrap items-center justify-between gap-3">
                            <span>
                                {t(`${W}.show.short_banner`, { date: new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeZone: order.storeTimezone }).format(new Date(order.shortOfStockAt)) })}
                            </span>
                            {a.mayEdit ? (
                                <ActionButton size="sm" variant="outline" loading={busy} onClick={() => post('clear-short')} data-test="clear-short">
                                    {t(`${W}.action.clear_short`)}
                                </ActionButton>
                            ) : null}
                        </span>
                    </Note>
                ) : null}

                <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid min-w-0 content-start gap-4">
                        <Lines order={order} />
                        <Totals order={order} />
                        <MoneyCard order={order} onRecord={() => setDialog('payment')} onRefund={() => setDialog('refund')} onVoid={(record) => setDialog({ void: record })} />
                        <History order={order} />
                    </div>
                    <div className="grid min-w-0 content-start gap-4">
                        <Contact order={order} />
                        <Shipment order={order} />
                        {order.transferDocuments.length > 0 ? <Files order={order} /> : null}
                        {order.returns.length > 0 ? <Returns order={order} /> : null}
                        {order.edits.length > 0 ? <Edits order={order} /> : null}
                    </div>
                </div>
            </div>

            {dialog === 'reject' || dialog === 'cancel' ? <ReasonDialog open onOpenChange={close} url={`${base}/${dialog}`} kind={dialog} /> : null}
            {dialog === 'ship' ? <ShipDialog open onOpenChange={close} url={`${base}/ship`} /> : null}
            {dialog === 'came_back' ? <CameBackDialog open onOpenChange={close} url={`${base}/came-back`} lines={order.lines} /> : null}
            {dialog === 'payment' ? <MoneyDialog open onOpenChange={close} url={`${base}/payment`} kind="payment" amount={order.owed} zone={order.storeTimezone} exponent={order.exponent} /> : null}
            {dialog === 'refund' ? <MoneyDialog open onOpenChange={close} url={`${base}/refund`} kind="refund" amount={order.held} zone={order.storeTimezone} exponent={order.exponent} /> : null}
            {dialog !== null && typeof dialog === 'object' ? <VoidDialog open onOpenChange={close} url={`${base}/void`} kind={dialog.void.kind} record={dialog.void.id} /> : null}
        </AdminLayout>
    );
}

/** An item of the ⋯ menu: out of reach where the order's state stops it, with that reason written under it. */
function MenuAction({ label, reason, onSelect, test, destructive = false }: { label: string; reason: string | null; onSelect: () => void; test: string; destructive?: boolean }) {
    return (
        <DropdownMenuItem
            variant={destructive ? 'destructive' : 'default'}
            aria-disabled={reason !== null || undefined}
            className={reason !== null ? 'cursor-not-allowed' : undefined}
            onSelect={(event) => {
                if (reason !== null) {
                    event.preventDefault();

                    return;
                }

                onSelect();
            }}
            data-test={test}
        >
            <span className="grid gap-0.5">
                <span className={reason !== null ? 'opacity-60' : undefined}>{label}</span>
                {reason !== null ? <span className="text-copy-12 text-ink-muted">{reason}</span> : null}
            </span>
        </DropdownMenuItem>
    );
}

function Section({ title, test, children, action }: { title: string; test: string; children: React.ReactNode; action?: React.ReactNode }) {
    return (
        <Card className="min-w-0 gap-3 py-4" data-test={test}>
            <CardHeader className="flex flex-row items-center justify-between gap-2 px-5">
                <CardTitle className="text-heading-16 text-ink">{title}</CardTitle>
                {action}
            </CardHeader>
            <CardContent className="px-5">{children}</CardContent>
        </Card>
    );
}

function Lines({ order }: { order: OrderPage }) {
    const t = useTranslator();

    return (
        <Section title={t(`${W}.show.lines`)} test="lines">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t(`${W}.show.lines`)}</TableHead>
                            <TableHead>{t(`${W}.show.code`)}</TableHead>
                            <TableHead className="text-end">{t(`${W}.show.pieces`)}</TableHead>
                            <TableHead className="text-end">{t(`${W}.show.unit`)}</TableHead>
                            <TableHead className="text-end">{t(`${W}.show.line_total`)}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {order.lines.map((line) => (
                            <TableRow key={line.id} data-test="line">
                                <TableCell className="grid gap-0.5">
                                    <span className="text-label-14 text-ink">
                                        {line.gift ? (
                                            <Badge className={`${tone('green-subtle')} me-2`} data-test="gift">
                                                {t(`${W}.show.gift`)}
                                            </Badge>
                                        ) : null}
                                        {line.name}
                                    </span>
                                    <span className="text-copy-13 text-ink-muted">
                                        {line.values}
                                        {line.values === '' ? '' : ' · '}
                                        {t(`${W}.mode.${line.mode}`)}
                                    </span>
                                </TableCell>
                                <TableCell className="text-copy-13">
                                    <bdi dir="ltr">{line.code}</bdi>
                                </TableCell>
                                <TableCell className="tw-figure text-end">{figure('en', line.quantity)}</TableCell>
                                <TableCell className="text-end">
                                    {line.listUnit.minor > line.unit.minor ? <Money value={line.listUnit} className="me-2 text-ink-muted line-through" /> : null}
                                    <Money value={line.unit} />
                                </TableCell>
                                <TableCell className="text-end">
                                    <Money value={line.total} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Section>
    );
}

/** A row of a summary: nothing when its amount is 0 (the owner's R2-15), unless it must show. */
function Row({ label, value, strong = false, always = false, minus = false }: { label: string; value: { text: string; minor: number }; strong?: boolean; always?: boolean; minus?: boolean }) {
    if (!always && value.minor === 0) {
        return null;
    }

    return (
        <div className={`flex items-baseline justify-between gap-3 ${strong ? 'text-label-16 text-ink' : 'text-copy-14 text-ink'}`}>
            <dt className={strong ? undefined : 'text-ink-muted'}>{label}</dt>
            <dd>
                {minus ? <span aria-hidden="true">− </span> : null}
                <Money value={value} />
            </dd>
        </div>
    );
}

function Totals({ order }: { order: OrderPage }) {
    const t = useTranslator();
    return (
        <Section title={t(`${W}.show.totals`)} test="totals">
            <dl className="grid gap-1.5">
                <Row label={t(`${W}.show.subtotal`)} value={order.grossSubtotal} always />
                <Row label={t(`${W}.show.savings`)} value={order.savings} minus />
                <Row label={t(`${W}.show.coupon`, { code: order.couponCode ?? '' })} value={order.couponDiscount} minus />
                <Row label={t(`${W}.show.points`, { points: figure('en', order.pointsUsed) })} value={order.pointsDiscount} minus />
                <Row label={t(`${W}.show.shipping`)} value={order.shipping} always />
                <Row label={t(`${W}.show.vat`)} value={order.vat} always />
                <Row label={t(`${W}.show.total`)} value={order.total} strong always />
            </dl>
            <p className="mt-3 text-copy-13 text-ink-muted">
                {t(`${W}.show.way_to_pay`)}: {t(`${W}.way.${order.wayToPay}`)}
            </p>
        </Section>
    );
}

function MoneyCard({ order, onRecord, onRefund, onVoid }: { order: OrderPage; onRecord: () => void; onRefund: () => void; onVoid: (record: MoneyRecordData) => void }) {
    const t = useTranslator();
    const may = order.actions.mayRecordMoney;

    return (
        <Section
            title={t(`${W}.show.money`)}
            test="money"
            action={
                may ? (
                    <span className="flex flex-wrap gap-2">
                        <ActionButton size="sm" variant="outline" disabledReason={order.owed.minor === 0 ? t(`${W}.why.nothing_owed`) : undefined} onClick={onRecord} data-test="record-payment">
                            {t(`${W}.action.payment`)}
                        </ActionButton>
                        <ActionButton size="sm" variant="outline" disabledReason={order.held.minor === 0 ? t(`${W}.why.nothing_held`) : undefined} onClick={onRefund} data-test="record-refund">
                            {t(`${W}.action.refund`)}
                        </ActionButton>
                    </span>
                ) : undefined
            }
        >
            <dl className="grid gap-1.5">
                <Row label={t(`${W}.show.due`)} value={order.due} always />
                <Row label={t(`${W}.show.paid`)} value={order.paid} always />
                <Row label={t(`${W}.show.refunded`)} value={order.refunded} />
                <Row label={t(`${W}.show.owed`)} value={order.owed} strong />
                <Row label={t(`${W}.show.refund_due`)} value={order.refundDue} strong />
            </dl>
            {order.money.length === 0 ? (
                <p className="mt-3 text-copy-13 text-ink-muted">{t(`${W}.show.no_records`)}</p>
            ) : (
                <ul className="mt-3 grid gap-2" aria-label={t(`${W}.show.records`)} data-test="money-records">
                    {order.money.map((record) => (
                        <li key={`${record.kind}-${record.id}`} className="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-2 text-copy-14" data-test="money-record">
                            <span className={record.voidedAt === null ? 'grid gap-0.5' : 'grid gap-0.5 text-ink-muted line-through'}>
                                <span>
                                    {t(`${W}.show.${record.kind === 'REFUND' ? 'refund_record' : 'payment_record'}`)} · <Money value={record.amount} /> · {t(`${W}.money_way.${record.way}`)}
                                    {record.reference === null ? null : (
                                        <>
                                            {' · '}
                                            <bdi>{record.reference}</bdi>
                                        </>
                                    )}
                                </span>
                                <span className="text-copy-13 text-ink-muted">
                                    <bdi dir="ltr">{record.happenedOn}</bdi> · <bdi>{record.recordedBy ?? t(`${W}.system`)}</bdi>
                                    {record.note === null ? null : (
                                        <>
                                            {' · '}
                                            <bdi>{record.note}</bdi>
                                        </>
                                    )}
                                </span>
                                {record.voidedAt === null ? null : <span className="text-copy-13 no-underline">{t(`${W}.show.voided`, { name: record.voidedBy ?? t(`${W}.system`), reason: record.voidReason ?? '' })}</span>}
                            </span>
                            {may && record.voidedAt === null ? (
                                <ActionButton
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    disabledReason={record.kind === 'PAYMENT' && order.paid.minor - record.amount.minor < order.refunded.minor ? t(`${W}.why.void_uncovers`) : undefined}
                                    onClick={() => onVoid(record)}
                                    data-test="void"
                                >
                                    {t(`${W}.action.void`)}
                                </ActionButton>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function Contact({ order }: { order: OrderPage }) {
    const t = useTranslator();
    const digits = order.contactPhone.replace(/\D/gu, '');

    return (
        <Section title={t(`${W}.show.customer`)} test="contact">
            <div className="grid gap-3 text-copy-14">
                <p className="grid gap-0.5">
                    <span className="text-label-14 text-ink">
                        <CustomerName customer={order.customer} />
                    </span>
                    <span className="text-copy-13 text-ink-muted">
                        {t(`${W}.show.audience`)}: {t(`${W}.audience.${order.audience}`)}
                    </span>
                </p>
                <div className="grid gap-1">
                    <span className="text-copy-13 text-ink-muted">{t(`${W}.show.contact`)}</span>
                    <span>{order.contactName}</span>
                    <span className="flex flex-wrap items-center gap-2">
                        <bdi dir="ltr" className="tw-figure" data-test="phone">
                            {order.contactPhone}
                        </bdi>
                        <CopyButton text={order.contactPhone} label={t(`${W}.show.copy`)} />
                        <Button asChild size="sm" variant="outline">
                            <a href={`https://wa.me/${digits}`} target="_blank" rel="noreferrer" data-test="whatsapp">
                                {t(`${W}.show.whatsapp`)}
                            </a>
                        </Button>
                    </span>
                    {order.contactEmail === null ? null : (
                        <bdi dir="ltr" className="text-copy-13" data-test="email">
                            {order.contactEmail}
                        </bdi>
                    )}
                </div>
                <div className="grid gap-1">
                    <span className="text-copy-13 text-ink-muted">{t(`${W}.show.address`)}</span>
                    <span>{order.recipientName}</span>
                    <AddressLines text={order.address} />
                    <bdi dir="ltr" className="tw-figure text-copy-13">
                        {order.addressPhone}
                    </bdi>
                </div>
            </div>
        </Section>
    );
}

function Shipment({ order }: { order: OrderPage }) {
    const t = useTranslator();
    const rows: [string, React.ReactNode][] = [];

    if (order.carrier !== null) rows.push([t(`${W}.show.carrier`), <bdi key="carrier">{order.carrier}</bdi>]);
    if (order.trackingNumber !== null) rows.push([t(`${W}.show.tracking`), <bdi key="tracking" dir="ltr">{order.trackingNumber}</bdi>]);
    if (order.shippedAt !== null) rows.push([t(`${W}.show.shipped_at`), <Time key="shipped" value={order.shippedAt} />]);
    if (order.deliveredAt !== null) rows.push([t(`${W}.show.delivered_at`), <Time key="delivered" value={order.deliveredAt} />]);
    if (order.returnDeadline !== null) rows.push([t(`${W}.show.return_deadline`), <Time key="deadline" value={order.returnDeadline} mode="absolute" />]);
    if (order.cameBackAt !== null) rows.push([t(`${W}.show.came_back_at`), <Time key="back" value={order.cameBackAt} />]);

    return (
        <Section title={t(`${W}.show.shipment`)} test="shipment">
            {rows.length === 0 ? <p className="text-copy-14 text-ink-muted">{t(`${W}.shipment.NOT_SHIPPED`)}</p> : null}
            <dl className="grid gap-1.5 text-copy-14">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
            {order.mayShipUnpaid ? <p className="mt-2 text-copy-13 text-ink-muted">{t(`${W}.show.unpaid_ship`)}</p> : null}
            {order.ticks.length > 0 ? (
                <div className="mt-3 grid gap-1 border-t border-line pt-3">
                    <p className="text-label-13 text-ink">{t(`${W}.show.ticks`)}</p>
                    {order.ticks.map((tick) => (
                        <p key={tick.variantId} className="text-copy-13 text-ink-muted">
                            {order.lines.find((line) => line.variantId === tick.variantId)?.name ?? tick.variantId} · {t(`${W}.show.ticked`, { name: tick.by ?? t(`${W}.system`) })} · <Time value={tick.at} />
                        </p>
                    ))}
                </div>
            ) : null}
        </Section>
    );
}

function Files({ order }: { order: OrderPage }) {
    const t = useTranslator();

    return (
        <Section title={t(`${W}.show.files`)} test="files">
            <ul className="grid gap-1 text-copy-14">
                {order.transferDocuments.map((file) => (
                    <li key={file.mediaId} className="flex items-center justify-between gap-2">
                        <a href={`/admin/orders/files/${file.mediaId}`} target="_blank" rel="noreferrer" className="text-ink underline underline-offset-4" data-test="file">
                            {t(`${W}.show.file`, { position: figure('en', file.position) })}
                        </a>
                        <span className="text-copy-13 text-ink-muted">
                            <Time value={file.uploadedAt} />
                        </span>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function Returns({ order }: { order: OrderPage }) {
    const t = useTranslator();

    return (
        <Section title={t(`${W}.show.returns`)} test="returns">
            <ul className="grid gap-1 text-copy-14">
                {order.returns.map((each) => (
                    <li key={each.id} className="flex flex-wrap items-center justify-between gap-2">
                        <span className="flex items-center gap-2">
                            <Badge className={tone('gray-subtle')}>{t(`${W}.return_status.${each.status}`)}</Badge>
                            <Time value={each.requestedAt} />
                        </span>
                        {each.refundDue === null ? null : <Money value={each.refundDue} />}
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function Edits({ order }: { order: OrderPage }) {
    const t = useTranslator();

    return (
        <Section title={t(`${W}.show.edits`)} test="edits">
            <ul className="grid gap-2 text-copy-14">
                {order.edits.map((edit) => (
                    <li key={edit.id} className="grid gap-0.5">
                        <span dir="auto">{edit.reason}</span>
                        <span className="text-copy-13 text-ink-muted">
                            {edit.by ?? t(`${W}.system`)} · <Time value={edit.at} />
                        </span>
                    </li>
                ))}
            </ul>
        </Section>
    );
}

function History({ order }: { order: OrderPage }) {
    const t = useTranslator();
    const rows = [...order.history].reverse();

    return (
        <Section title={t(`${W}.show.history`)} test="history">
            <ol className="grid gap-2 text-copy-14">
                {rows.map((row, index) => (
                    <li key={index} className="flex flex-wrap items-baseline justify-between gap-2" data-test="history-row">
                        <span>
                            {row.amount === null ? t(`${W}.history.${row.kind}`) : t(`${W}.history.money`, { what: t(`${W}.history.${row.kind}`), amount: row.amount.text, way: row.way === null ? '' : t(`${W}.money_way.${row.way}`) })}
                            {row.by === null ? null : (
                                <>
                                    {' · '}
                                    <bdi>{row.by}</bdi>
                                </>
                            )}
                        </span>
                        <span className="text-copy-13 text-ink-muted">
                            <Time value={row.at} />
                        </span>
                    </li>
                ))}
            </ol>
        </Section>
    );
}
