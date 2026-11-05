import { Link, router } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import { usePage } from '@inertiajs/react';
import type { PaymentRowData, PaymentsListPage } from '@/types/generated/Modules/Payments/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The Payments list (payments.md §4.2, Q19; §9.14 #10): the newest 50 needing attention first, each saying why, then the
| rest 50 at a time with Load More. Filters: store, state and mode. A row opens its attempt; its order's number opens the
| order. One card per row on a phone. No Test badge: the order's number says it (owner, 2026-11-03).
*/

export const A = 'payments::admin_payments';

const TONES: Record<string, Tone> = { SUCCEEDED: 'green-subtle', FAILED: 'red-subtle', REFUSED: 'red-subtle', UNKNOWN: 'amber-subtle', OPEN: 'blue-subtle', CHARGING: 'blue-subtle' };

export function AttemptState({ state }: { state: string }) {
    const t = useTranslator();

    return (
        <Badge className={tone(TONES[state] ?? 'gray-subtle')} data-test="attempt-state">
            {t(`${A}.state_name.${state}`)}
        </Badge>
    );
}

export default function Payments(page: PaymentsListPage) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const list = useLoadMore<PaymentRowData>(page.rows, (row) => row.id, page.needingAttention);
    const filter = (key: 'state' | 'mode', value: string) =>
        router.get('/admin/payments', { ...(page.storeCode === null ? {} : { store: page.storeCode }), ...(page.state === null || key === 'state' ? {} : { state: page.state }), ...(page.mode === null || key === 'mode' ? {} : { mode: page.mode }), ...(value === '' ? {} : { [key]: value }) }, { preserveState: false });

    return (
        <AdminLayout title={t(`${A}.title`)} subtitle={t(`${A}.subtitle`)}>
            <div className="grid min-w-0 gap-4">
                <div className="flex flex-wrap items-end gap-3">
                    <StoreFilter stores={page.stores} value={page.storeCode} all className="max-w-xs" />
                    <NativeSelect aria-label={t(`${A}.filter_state`)} value={page.state ?? ''} onChange={(event) => filter('state', event.target.value)} data-test="filter-state">
                        <NativeSelectOption value="">{t(`${A}.all_states`)}</NativeSelectOption>
                        {page.states.map((state) => (
                            <NativeSelectOption key={state} value={state}>
                                {t(`${A}.state_name.${state}`)}
                            </NativeSelectOption>
                        ))}
                    </NativeSelect>
                    <NativeSelect aria-label={t(`${A}.filter_mode`)} value={page.mode ?? ''} onChange={(event) => filter('mode', event.target.value)} data-test="filter-mode">
                        <NativeSelectOption value="">{t(`${A}.all_modes`)}</NativeSelectOption>
                        <NativeSelectOption value="LIVE">{t(`${A}.mode.LIVE`)}</NativeSelectOption>
                        <NativeSelectOption value="TEST">{t(`${A}.mode.TEST`)}</NativeSelectOption>
                    </NativeSelect>
                </div>

                {page.needingAttention.length === 0 ? null : (
                    <Card className="min-w-0 gap-3 py-4" data-test="needing-attention">
                        <CardHeader className="px-4">
                            <CardTitle className="text-heading-16">
                                {t(`${A}.needing_attention`)} <span className="tw-figure text-copy-14 text-ink-muted">({figure(locale, page.needingAttentionCount)})</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4">
                            <Rows rows={page.needingAttention} reasons />
                        </CardContent>
                    </Card>
                )}

                <Card className="min-w-0 gap-3 py-4" data-test="payments">
                    <CardHeader className="px-4">
                        <CardTitle className="text-heading-16">{t(`${A}.rest`)}</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4">
                        {list.rows.length === 0 ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${A}.none`)}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Rows rows={list.rows} />
                        )}
                    </CardContent>
                </Card>
                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/payments', { ...(page.storeCode === null ? {} : { store: page.storeCode }), ...(page.state === null ? {} : { state: page.state }), ...(page.mode === null ? {} : { mode: page.mode }), after: page.next ?? '' }, ['rows', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}

function OrderLink({ row }: { row: PaymentRowData }) {
    const t = useTranslator();

    return row.orderNumber === null ? (
        <span className="text-ink-muted">{t(`${A}.no_order`)}</span>
    ) : (
        <Link href={`/admin/orders/${row.orderId}`} className="tw-figure text-ink underline underline-offset-4" dir="ltr">
            {row.orderNumber}
        </Link>
    );
}

function Rows({ rows, reasons = false }: { rows: PaymentRowData[]; reasons?: boolean }) {
    const t = useTranslator();

    return (
        <>
            {/* A phone: one card per row. */}
            <ul className="grid gap-2 md:hidden">
                {rows.map((row) => (
                    <li key={row.id} className="grid gap-1 border-t border-line pt-2 text-copy-14 first:border-t-0 first:pt-0" data-test="payment-row">
                        <span className="flex flex-wrap items-center justify-between gap-2">
                            <Link href={`/admin/payments/${row.id}`} className="text-label-14 text-ink underline underline-offset-4" data-test="attempt-link">
                                <Money value={row.amount} />
                            </Link>
                            <AttemptState state={row.state} />
                        </span>
                        <span className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                            <OrderLink row={row} />
                            {row.customerName === null ? null : <bdi>{row.customerName}</bdi>}
                            {row.method === null ? null : <span>{row.method}</span>}
                            <Time value={row.createdAt} focusable={false} />
                        </span>
                        {reasons ? row.reasons.map((reason) => <span key={reason} className="text-copy-13 text-ink" data-test="payment-reason">{t(`${A}.reason.${reason}`)}</span>) : null}
                    </li>
                ))}
            </ul>
            <Table className="hidden md:table">
                <TableHeader>
                    <TableRow>
                        <TableHead>{t(`${A}.when`)}</TableHead>
                        <TableHead>{t(`${A}.order`)}</TableHead>
                        <TableHead>{t(`${A}.customer`)}</TableHead>
                        <TableHead>{t(`${A}.amount`)}</TableHead>
                        <TableHead>{t(`${A}.method`)}</TableHead>
                        <TableHead>{t(`${A}.state`)}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.id} data-test="payment-row">
                            <TableCell>
                                <Link href={`/admin/payments/${row.id}`} className="text-ink underline underline-offset-4" data-test="attempt-link">
                                    <Time value={row.createdAt} focusable={false} />
                                </Link>
                            </TableCell>
                            <TableCell>
                                <OrderLink row={row} />
                            </TableCell>
                            <TableCell>{row.customerName === null ? null : <bdi>{row.customerName}</bdi>}</TableCell>
                            <TableCell>
                                <Money value={row.amount} />
                            </TableCell>
                            <TableCell>{row.method}</TableCell>
                            <TableCell>
                                <span className="grid gap-1">
                                    <AttemptState state={row.state} />
                                    {reasons ? row.reasons.map((reason) => <span key={reason} className="text-copy-13 text-ink" data-test="payment-reason">{t(`${A}.reason.${reason}`)}</span>) : null}
                                </span>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </>
    );
}
