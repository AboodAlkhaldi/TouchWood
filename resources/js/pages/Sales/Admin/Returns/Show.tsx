import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextareaField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { MoneyDialog, W } from '@/components/sales/admin';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { ReturnPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { CustomerName } from '../Orders/Index';

/*
| P6 - a return in the panel (sales.md §4.1): its order linked, the customer's reason, the photos opening full
| size, each line asked / came / back in stock, and the actions its state allows - Accept, Refuse… with a reason,
| Mark Received… (what was asked first, then what came first - the owner's R2-39), Close, Complete…, and then
| Record Refund…, filled with the refund, recorded on the order (R1-52).
*/

const R = 'sales::admin_returns';

export default function Show(item: ReturnPage) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<'refuse' | 'receive' | 'complete' | 'refund' | null>(null);
    const [busy, setBusy] = useState(false);
    const base = `/admin/returns/${item.id}`;
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const post = (action: string) => router.post(`${base}/${action}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const may = item.mayManage;

    const main =
        !may ? null : item.status === 'REQUESTED' ? (
            <ActionButton loading={busy} onClick={() => post('accept')} data-test="accept">
                {t(`${R}.action.accept`)}
            </ActionButton>
        ) : item.status === 'ACCEPTED' ? (
            <ActionButton onClick={() => setDialog('receive')} data-test="receive">
                {t(`${R}.action.receive`)}
            </ActionButton>
        ) : item.status === 'RECEIVED' ? (
            <ActionButton onClick={() => setDialog('complete')} data-test="complete">
                {t(`${R}.action.complete`)}
            </ActionButton>
        ) : item.status === 'COMPLETED' && item.mayRecordMoney && item.refundDue !== null && item.refundDue.minor > 0 ? (
            <ActionButton onClick={() => setDialog('refund')} data-test="record-refund">
                {t(`${R}.action.record_refund`)}
            </ActionButton>
        ) : null;

    const second =
        !may ? null : item.status === 'REQUESTED' ? (
            <ActionButton variant="outline" onClick={() => setDialog('refuse')} data-test="refuse">
                {t(`${R}.action.refuse`)}
            </ActionButton>
        ) : item.status === 'ACCEPTED' ? (
            <ActionButton variant="outline" loading={busy} onClick={() => post('close')} data-test="close">
                {t(`${R}.action.close`)}
            </ActionButton>
        ) : null;

    return (
        <AdminLayout
            title={t(`${R}.show.title`, { number: item.orderNumber })}
            breadcrumbs={[{ label: t(`${R}.returns.title`), href: '/admin/returns' }]}
            action={
                <span className="flex flex-wrap items-center gap-2">
                    {second}
                    {main}
                </span>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <div className="flex flex-wrap items-center gap-2">
                    <Badge className={tone('gray-subtle')} data-test="return-status">
                        {t(`${W}.return_status.${item.status}`)}
                    </Badge>
                    {item.store.isActive ? null : <Badge className={tone('gray')}>{t(`${W}.off`)}</Badge>}
                    <Link href={`/admin/orders/${item.orderId}`} className="text-copy-14 text-ink underline underline-offset-4" data-test="return-order">
                        {t(`${R}.show.order`, { number: item.orderNumber })}
                    </Link>
                    <span className="text-copy-13 text-ink-muted">
                        <CustomerName customer={item.customer} />
                    </span>
                </div>

                <div className="grid grid-cols-[minmax(0,1fr)] gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="grid min-w-0 content-start gap-4">
                        <Card className="min-w-0 gap-3 py-4" data-test="return-lines">
                            <CardHeader className="px-5">
                                <CardTitle className="text-heading-16 text-ink">{t(`${R}.show.lines`)}</CardTitle>
                            </CardHeader>
                            <CardContent className="overflow-x-auto px-5">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t(`${R}.show.lines`)}</TableHead>
                                            <TableHead className="text-end">{t(`${R}.show.asked`)}</TableHead>
                                            <TableHead className="text-end">{t(`${R}.show.received`)}</TableHead>
                                            <TableHead className="text-end">{t(`${R}.show.restocked`)}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {item.lines.map((line) => (
                                            <TableRow key={line.orderLineId}>
                                                <TableCell className="grid gap-0.5">
                                                    <span className="text-label-14 text-ink">
                                                        {line.gift ? `${t(`${W}.show.gift`)}: ` : ''}
                                                        {line.name}
                                                    </span>
                                                    <span className="text-copy-13 text-ink-muted">
                                                        {line.values}
                                                        {line.values === '' ? '' : ' · '}
                                                        <bdi dir="ltr">{line.code}</bdi>
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tw-figure text-end">{figure('en', line.asked)}</TableCell>
                                                <TableCell className="tw-figure text-end">{line.received === null ? '—' : figure('en', line.received)}</TableCell>
                                                <TableCell className="tw-figure text-end">{line.restocked === null ? '—' : figure('en', line.restocked)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>

                        {item.photos.length > 0 ? (
                            <Card className="min-w-0 gap-3 py-4" data-test="return-photos">
                                <CardHeader className="px-5">
                                    <CardTitle className="text-heading-16 text-ink">{t(`${R}.show.photos`)}</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2 px-5">
                                    {item.photos.map((photo) => (
                                        <a key={photo.mediaId} href={`/admin/orders/files/${photo.mediaId}`} target="_blank" rel="noreferrer" className="text-copy-14 text-ink underline underline-offset-4" data-test="photo">
                                            {t(`${R}.show.photo`, { position: figure('en', photo.position) })}
                                        </a>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <Card className="min-w-0 content-start gap-3 py-4" data-test="return-facts">
                        <CardHeader className="px-5">
                            <CardTitle className="text-heading-16 text-ink">{t(`${R}.show.why`)}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 px-5 text-copy-14">
                            <p>
                                {t(`${R}.reason.${item.reason}`)}
                                {item.reasonOther === null ? null : (
                                    <span className="block text-ink-muted" dir="auto">
                                        {item.reasonOther}
                                    </span>
                                )}
                            </p>
                            {item.staffReason === null ? null : <p className="text-ink-muted">{t(`${R}.show.staff_reason`, { reason: item.staffReason })}</p>}
                            {item.decidedBy === null ? null : <p className="text-copy-13 text-ink-muted">{t(`${R}.show.decided`, { name: item.decidedBy })}</p>}
                            <dl className="grid gap-1 text-copy-13">
                                {(
                                    [
                                        [`${W}.return_status.REQUESTED`, item.requestedAt],
                                        [`${W}.return_status.WITHDRAWN`, item.withdrawnAt],
                                        [`${W}.return_status.RECEIVED`, item.receivedAt],
                                        [`${W}.return_status.CLOSED`, item.closedAt],
                                        [`${W}.return_status.COMPLETED`, item.completedAt],
                                    ] as [string, string | null][]
                                )
                                    .filter(([, at]) => at !== null)
                                    .map(([key, at]) => (
                                        <div key={key} className="flex justify-between gap-3">
                                            <dt className="text-ink-muted">{t(key)}</dt>
                                            <dd>
                                                <Time value={at as string} />
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                            {item.refundDue === null ? null : (
                                <p className="flex items-baseline justify-between gap-3 text-label-14" data-test="refund-due">
                                    <span>{t(`${R}.show.refund`)}</span>
                                    <Money value={item.refundDue} />
                                </p>
                            )}
                            {item.shippingRefunded ? <p className="text-copy-13 text-ink-muted">{t(`${R}.show.shipping_refunded`)}</p> : null}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {dialog === 'refuse' ? <RefuseDialog url={`${base}/refuse`} onOpenChange={close} /> : null}
            {dialog === 'receive' ? <ReceiveDialog item={item} url={`${base}/receive`} onOpenChange={close} /> : null}
            {dialog === 'complete' ? <CompleteDialog url={`${base}/complete`} preview={item.preview} onOpenChange={close} /> : null}
            {dialog === 'refund' && item.refundDue !== null ? (
                <MoneyDialog open onOpenChange={close} url={`/admin/orders/${item.orderId}/refund`} kind="refund" amount={item.refundDue} zone={item.storeTimezone} exponent={item.exponent} initial={item.refundDueDecimal ?? ''} />
            ) : null}
        </AdminLayout>
    );
}

function RefuseDialog({ url, onOpenChange }: { url: string; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const checks = useChecks([{ id: 'refuse-reason', label: t(`${R}.dialog.reason`), value: reason, rules: { required: true, length: { max: 500 } } }]);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            title={t(`${R}.dialog.refuse_title`)}
            description={t(`${R}.dialog.refuse_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => router.post(url, { reason }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-refuse"
                >
                    {t(`${R}.dialog.refuse_title`)}
                </ActionButton>
            }
        >
            <TextareaField id="refuse-reason" rows={3} label={t(`${R}.dialog.reason`)} value={reason} check={checks.box('refuse-reason')} onChange={(event) => setReason(event.target.value)} data-test="refuse-reason" />
        </PanelDialog>
    );
}

/** Mark Received…: every line once - how many came, what was asked first; how many back to stock, what came first (R2-39). */
function ReceiveDialog({ item, url, onOpenChange }: { item: ReturnPage; url: string; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const [came, setCame] = useState<Record<string, number>>({});
    const [back, setBack] = useState<Record<string, number>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setCame(Object.fromEntries(item.lines.map((line) => [line.orderLineId, line.asked])));
        setBack(Object.fromEntries(item.lines.map((line) => [line.orderLineId, line.asked])));
    }, [item.lines]);

    const lines = item.lines.map((line) => ({ orderLineId: line.orderLineId, received: came[line.orderLineId] ?? 0, restocked: Math.min(back[line.orderLineId] ?? 0, came[line.orderLineId] ?? 0) }));

    return (
        <PanelDialog
            open
            wide
            onOpenChange={onOpenChange}
            title={t(`${R}.dialog.receive_title`)}
            description={t(`${R}.dialog.receive_body`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} onClick={() => router.post(url, { lines }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })} data-test="confirm-receive">
                    {t(`${R}.dialog.receive_title`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {item.lines.map((line) => {
                    const cameNow = came[line.orderLineId] ?? 0;

                    return (
                        <fieldset key={line.orderLineId} className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_7rem] sm:items-end">
                            <legend className="mb-1 text-label-14 text-ink">
                                {line.gift ? `${t(`${W}.show.gift`)}: ` : ''}
                                {line.name}
                                {line.values === '' ? '' : ` · ${line.values}`}
                            </legend>
                            <span className="hidden sm:block" />
                            <TextField
                                id={`came-${line.orderLineId}`}
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={line.asked}
                                inputClassName="tw-figure"
                                label={t(`${R}.dialog.came`)}
                                value={String(cameNow)}
                                onChange={(event) => {
                                    const next = Math.max(0, Math.min(line.asked, Number(event.target.value) || 0));
                                    setCame({ ...came, [line.orderLineId]: next });
                                    setBack({ ...back, [line.orderLineId]: Math.min(back[line.orderLineId] ?? next, next) });
                                }}
                                data-test={`came-${line.orderLineId}`}
                            />
                            <TextField
                                id={`back-${line.orderLineId}`}
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={cameNow}
                                inputClassName="tw-figure"
                                label={t(`${R}.dialog.back`)}
                                value={String(Math.min(back[line.orderLineId] ?? 0, cameNow))}
                                onChange={(event) => setBack({ ...back, [line.orderLineId]: Math.max(0, Math.min(cameNow, Number(event.target.value) || 0)) })}
                                data-test={`back-${line.orderLineId}`}
                            />
                        </fieldset>
                    );
                })}
            </div>
        </PanelDialog>
    );
}

/** Complete…: a confirm showing the refund worked out - the pieces, their VAT, the shipping when it is the last piece (R2-40). */
function CompleteDialog({ url, preview, onOpenChange }: { url: string; preview: ReturnPage['preview']; onOpenChange: (open: boolean) => void }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            title={t(`${R}.dialog.complete_title`)}
            description={t(`${R}.dialog.complete_body`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} onClick={() => router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })} data-test="confirm-complete">
                    {t(`${R}.dialog.complete_title`)}
                </ActionButton>
            }
        >
            {preview === null ? null : (
                <dl className="grid gap-1.5 text-copy-14" data-test="refund-preview">
                    {(
                        [
                            ['preview_goods', preview.goods, true],
                            ['preview_vat', preview.vat, true],
                            ['preview_shipping', preview.shipping, false],
                        ] as const
                    )
                        .filter(([, value, always]) => always || value.minor > 0)
                        .map(([key, value]) => (
                            <div key={key} className="flex items-baseline justify-between gap-3">
                                <dt className="text-ink-muted">{t(`${R}.dialog.${key}`)}</dt>
                                <dd>
                                    <Money value={value} />
                                </dd>
                            </div>
                        ))}
                    <div className="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                        <dt className="text-label-14 text-ink">{t(`${R}.dialog.preview_total`)}</dt>
                        <dd>
                            <Money value={preview.total} className="text-label-14 text-ink" />
                        </dd>
                    </div>
                </dl>
            )}
        </PanelDialog>
    );
}
