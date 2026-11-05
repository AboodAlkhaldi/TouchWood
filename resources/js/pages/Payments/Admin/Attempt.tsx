import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { AttemptCallData, AttemptPage } from '@/types/generated/Modules/Payments/Presentation/Http/Resource';
import { A, AttemptState } from './Payments';

/*
| An attempt (payments.md §4.2, Q20, Q21): its state and why, who it was for and its order, the customer's details for staff
| who may read them, its transactions as MyFatoorah reported them, and every call with what was sent and answered (keys
| and card details were never stored). Ask the Gateway Now while it is open or unknown. A refund due says how, with no
| refund button.
*/

export default function Attempt(page: AttemptPage) {
    const t = useTranslator();
    const [asking, setAsking] = useState(false);
    const row = page.attempt;
    const ask = () => router.post(`/admin/payments/${row.id}/check`, {}, { preserveScroll: true, onStart: () => setAsking(true), onFinish: () => setAsking(false) });

    return (
        <AdminLayout
            title={t(`${A}.attempt`)}
            breadcrumbs={[{ label: t(`${A}.title`), href: '/admin/payments' }]}
            action={
                <ActionButton loading={asking} disabledReason={page.canAsk ? undefined : t(`${A}.ask_closed`)} onClick={ask} data-test="ask-gateway">
                    {t(`${A}.ask`)}
                </ActionButton>
            }
        >
            <div className="grid min-w-0 gap-4">
                <FormError />
                {page.refundDue ? (
                    <Note variant="warning" data-test="refund-due">
                        {t(`${A}.refund_due`)}
                    </Note>
                ) : null}
                {row.reasons.length === 0
                    ? null
                    : row.reasons.map((reason) => (
                          <Note key={reason} variant="secondary" data-test="attempt-reason">
                              {t(`${A}.reason.${reason}`)}
                          </Note>
                      ))}

                <Card className="min-w-0 gap-3 py-4" data-test="attempt">
                    <CardHeader className="px-4">
                        <CardTitle className="text-heading-16">{t(`${A}.details`)}</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4">
                        <dl className="grid gap-2 text-copy-14 sm:grid-cols-[max-content_minmax(0,1fr)] sm:gap-x-6">
                            <dt className="text-ink-muted">{t(`${A}.state`)}</dt>
                            <dd>
                                <AttemptState state={row.state} />
                            </dd>
                            <dt className="text-ink-muted">{t(`${A}.amount`)}</dt>
                            <dd>
                                <Money value={row.amount} /> · {t(`${A}.mode.${page.isTest ? 'TEST' : 'LIVE'}`)}
                            </dd>
                            <dt className="text-ink-muted">{t(`${A}.for`)}</dt>
                            <dd className="flex flex-wrap items-center gap-2">
                                {t(`${A}.owner.${row.ownerKind}`)}
                                {row.orderNumber === null ? (
                                    <span className="text-ink-muted">· {t(`${A}.no_order`)}</span>
                                ) : (
                                    <Link href={`/admin/orders/${row.orderId}`} className="tw-figure text-ink underline underline-offset-4" dir="ltr" data-test="attempt-order">
                                        {row.orderNumber}
                                    </Link>
                                )}
                            </dd>
                            {row.method === null ? null : (
                                <>
                                    <dt className="text-ink-muted">{t(`${A}.method`)}</dt>
                                    <dd>{row.method}</dd>
                                </>
                            )}
                            <dt className="text-ink-muted">{t(`${A}.created`)}</dt>
                            <dd>
                                <Time value={row.createdAt} mode="absolute" />
                            </dd>
                            <dt className="text-ink-muted">{t(`${A}.customer`)}</dt>
                            <dd className="grid gap-0.5" data-test="attempt-contact">
                                {page.mayReadContact ? (
                                    <>
                                        {page.contactName === null ? null : <bdi>{page.contactName}</bdi>}
                                        {page.contactEmail === null ? null : <bdi dir="ltr">{page.contactEmail}</bdi>}
                                        {page.contactPhone === null ? null : <bdi dir="ltr">{page.contactPhone}</bdi>}
                                    </>
                                ) : (
                                    <>
                                        {row.customerName === null ? null : <bdi>{row.customerName}</bdi>}
                                        <span className="text-copy-13 text-ink-muted">{t(`${A}.contact_hidden`)}</span>
                                    </>
                                )}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <Card className="min-w-0 gap-3 py-4" data-test="transactions">
                    <CardHeader className="px-4">
                        <CardTitle className="text-heading-16">{t(`${A}.transactions`)}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-4">
                        {page.transactions.length === 0 ? (
                            <p className="text-copy-14 text-ink-muted">{t(`${A}.no_transactions`)}</p>
                        ) : (
                            page.transactions.map((each) => (
                                <dl key={each.id} className="grid gap-1 border-t border-line pt-3 text-copy-14 first:border-t-0 first:pt-0 sm:grid-cols-[max-content_minmax(0,1fr)] sm:gap-x-6" data-test="transaction">
                                    <dt className="text-ink-muted">{t(`${A}.gateway_id`)}</dt>
                                    <dd className="tw-figure break-all" dir="ltr">
                                        {each.gatewayPaymentId}
                                    </dd>
                                    <dt className="text-ink-muted">{t(`${A}.state`)}</dt>
                                    <dd>{each.status}</dd>
                                    <dt className="text-ink-muted">{t(`${A}.method`)}</dt>
                                    <dd>
                                        {each.method}
                                        {each.cardBrand === null ? null : ` · ${each.cardBrand}`}
                                    </dd>
                                    {each.amount === null ? null : (
                                        <>
                                            <dt className="text-ink-muted">{t(`${A}.amount`)}</dt>
                                            <dd className="tw-figure" dir="ltr">
                                                {each.amount}
                                            </dd>
                                        </>
                                    )}
                                    {each.paidAt === null ? null : (
                                        <>
                                            <dt className="text-ink-muted">{t(`${A}.paid_at`)}</dt>
                                            <dd>
                                                <Time value={each.paidAt} mode="absolute" />
                                            </dd>
                                        </>
                                    )}
                                    {each.errorCode === null && each.errorMessage === null ? null : (
                                        <>
                                            <dt className="text-ink-muted">{t(`${A}.error`)}</dt>
                                            <dd>
                                                <bdi>{[each.errorCode, each.errorMessage].filter((part) => part !== null).join(' · ')}</bdi>
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0 gap-3 py-4" data-test="calls">
                    <CardHeader className="px-4">
                        <CardTitle className="text-heading-16">{t(`${A}.calls`)}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 px-4">
                        {page.calls.length === 0 ? <p className="text-copy-14 text-ink-muted">{t(`${A}.no_calls`)}</p> : page.calls.map((call) => <Call key={call.id} call={call} />)}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}

/** A call, opening to what was sent and what came back (Q20). */
function Call({ call }: { call: AttemptCallData }) {
    const t = useTranslator();

    return (
        <Collapsible className="border-t border-line pt-2 first:border-t-0 first:pt-0" data-test="call">
            <CollapsibleTrigger className="flex w-full flex-wrap items-center justify-between gap-2 text-start text-copy-14 text-ink">
                <span className="flex flex-wrap items-center gap-2">
                    <Time value={call.at} mode="absolute" focusable={false} />
                    <code className="tw-figure break-all text-copy-13" dir="ltr">
                        {call.endpoint}
                    </code>
                </span>
                <span className="flex items-center gap-2 text-copy-13 text-ink-muted">
                    <span className="tw-figure" dir="ltr">
                        {call.httpStatus ?? '—'}
                    </span>
                    <span>{t(`${A}.took`, { ms: String(call.tookMs) })}</span>
                    <ChevronDown aria-hidden="true" className="size-4" />
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-2 grid min-w-0 gap-2">
                {call.error === null ? null : <p className="text-copy-13 text-ink">{call.error}</p>}
                <Body label={t(`${A}.request`)} body={call.request} />
                <Body label={t(`${A}.response`)} body={call.response} />
            </CollapsibleContent>
        </Collapsible>
    );
}

function Body({ label, body }: { label: string; body: string | null }) {
    const t = useTranslator();

    return (
        <div className="grid min-w-0 gap-1">
            <span className="text-label-13 text-ink-muted">{label}</span>
            {body === null ? (
                <span className="text-copy-13 text-ink-muted">{t(`${A}.nothing_stored`)}</span>
            ) : (
                <pre className="max-h-80 overflow-auto rounded-md bg-surface-sunken p-3 text-copy-13" dir="ltr" data-test="call-body">
                    {body}
                </pre>
            )}
        </div>
    );
}
