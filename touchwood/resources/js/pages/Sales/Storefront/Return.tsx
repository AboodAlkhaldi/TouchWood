import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { Time } from '@/components/Time';
import { O, plural, useLocale } from '@/components/sales/shop';
import { Card, CardContent } from '@/components/ui/card';
import { AccountLayout } from '@/layouts/AccountLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { OwnReturnPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| S10 - a return of the customer's (sales.md §4.1): its state, the pieces asked and received, the refund, the
| store's reason when refused, and Withdraw while it waits for the store (§1.8).
*/

export default function Return(item: OwnReturnPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const [withdrawing, setWithdrawing] = useState(false);
    const [busy, setBusy] = useState(false);
    const rows: [string, ReactNode][] = [
        [t(`${O}.return.state`), t(`${O}.returns.status.${item.status}`)],
        [t(`${O}.returns.asked_on`), <Time key="asked" value={item.requestedAt} />],
        [t(`${O}.return.reason`), item.reason === 'OTHER' && item.reasonOther !== null ? <bdi key="other">{item.reasonOther}</bdi> : t(`${O}.ask.reasons.${item.reason}`)],
    ];

    if (item.staffReason !== null) rows.push([t(`${O}.return.store_reason`), <bdi key="store">{item.staffReason}</bdi>]);
    if (item.refundDue !== null) rows.push([t(`${O}.return.refund`), <Money key="refund" value={item.refundDue} />]);

    return (
        <AccountLayout title={t(`${O}.return.title`)} subtitle={t(`${O}.return.subtitle`, { number: item.orderNumber })} page="sales.orders">
            <div className="grid min-w-0 content-start gap-4">
                <FormError />
                <Card className="material-base gap-3 border-0 py-4" data-test="own-return">
                    <CardContent className="grid gap-4 px-4">
                        <dl className="grid gap-1.5 text-copy-14">
                            {rows.map(([label, value]) => (
                                <div key={label} className="flex items-baseline justify-between gap-3">
                                    <dt className="text-ink-muted">{label}</dt>
                                    <dd className="min-w-0 text-end text-ink">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <div className="grid gap-2 border-t border-line pt-3">
                            <h2 className="text-label-14 text-ink">{t(`${O}.return.lines`)}</h2>
                            <ul className="grid gap-2">
                                {item.lines.map((line, index) => (
                                    <li key={`${line.code}-${index}`} className="grid gap-0.5 text-copy-14">
                                        <span className="text-ink">{line.name}</span>
                                        {line.values === '' ? null : <span className="text-copy-13 text-ink-muted">{line.values}</span>}
                                        <span className="text-copy-13 text-ink-muted">
                                            {t(`${O}.return.asked`, { count: figure(locale, line.asked) })}
                                            {line.received === null ? null : ` · ${t(`${O}.return.received`, { count: figure(locale, line.received) })}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-copy-13 text-ink-muted">{plural(t, locale, `${O}.return.photos`, item.photos)}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            {item.status === 'REQUESTED' ? (
                                <ActionButton variant="outline" onClick={() => setWithdrawing(true)} data-test="withdraw">
                                    {t(`${O}.return.withdraw`)}
                                </ActionButton>
                            ) : null}
                            <Link href={link('storefront.sales.order', { number: item.orderNumber })} className="text-copy-14 text-ink underline underline-offset-4" data-test="to-order">
                                {t(`${O}.return.to_order`)}
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
            {withdrawing ? (
                <PanelDialog
                    open
                    destructive
                    onOpenChange={setWithdrawing}
                    title={t(`${O}.return.withdraw_title`)}
                    description={t(`${O}.return.withdraw_body`)}
                    busy={busy}
                    cancelLabel={t(`${O}.return.keep`)}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(link('storefront.sales.return.withdraw', { return: item.id }), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setWithdrawing(false) })
                            }
                            data-test="confirm-withdraw"
                        >
                            {t(`${O}.return.withdraw`)}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            ) : null}
        </AccountLayout>
    );
}
