import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Description } from '@/components/geist-only/Description';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { ChangePointsDialog } from '@/pages/Loyalty/Admin/Points/ChangePointsDialog';
import { Amount, LotsTable, Points, ProgrammeOffNote, StaffHistory, useDay, useLocale } from '@/components/loyalty/parts';
import type { CustomerPointsPage } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';

/*
| L2 - one customer's points in the store the filter shows (loyalty.md §4.1): the balance and what it
| is worth, what expires next (answer 11), every live lot, and the history newest first, 50 at a time
| with Show More. A change by hand made here appears at the top of the history.
|
| Change Points… and Open Customer are offered only when Loyalty says so for this reader
| (LoyaltyActionsForReader); each handler behind them asks again (handoff §19).
|
| A customer with no points here - reached only by somebody who may view the customer - reads as an
| empty state, and may still be given points: Change Points… opens on Add.
*/

export default function Customer({
    customer,
    points,
    worth,
    expiring,
    lots,
    history,
    nextAt,
    nextId,
    mayChangePoints,
    mayOpenCustomer,
    mostByHand,
    storeCode,
    storeName,
    storeIsActive,
    programmeOn,
    stores,
}: CustomerPointsPage) {
    const t = useTranslator();
    const locale = useLocale();
    const day = useDay();
    const [changing, setChanging] = useState(false);
    const list = useLoadMore(history, (row) => String(row.id));
    const name = customer.deleted ? t('loyalty::admin_points.deleted_customer') : (customer.name ?? '');
    const address = `/admin/points/customers/${customer.id}`;
    const nothingHere = lots.length === 0 && list.rows.length === 0;

    return (
        <AdminLayout
            title={name}
            subtitle={t('loyalty::admin_points.customer.subtitle')}
            breadcrumbs={[
                { label: t('loyalty::admin_points.breadcrumb') },
                { label: t('loyalty::admin_points.customers.title'), href: `/admin/points/customers?store=${encodeURIComponent(storeCode)}` },
            ]}
            action={
                mayOpenCustomer ? (
                    <Button asChild variant="outline">
                        <Link href={`/admin/customers/${customer.id}`} data-test="open-customer">
                            {t('loyalty::admin_points.customer.open_customer')}
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-6">
                <FormError />
                <ProgrammeOffNote on={programmeOn} />
                <StoreFilter stores={stores} value={storeCode} className="max-w-xs" />

                <Card className="material-base gap-4 border-0 py-6" data-test="balance-card">
                    <CardHeader className="px-6">
                        <CardTitle className="inline-flex items-center gap-1.5 text-heading-16 text-ink">
                            <h2>{storeName}</h2>
                            {storeIsActive ? null : <StoreOffBadge />}
                        </CardTitle>
                        {mayChangePoints ? (
                            <CardAction>
                                {/* It opens a dialog, so it ends in "…" (the writing rules, frontend.md §1.10). */}
                                <Button variant="outline" size="sm" onClick={() => setChanging(true)} data-test="change-points">
                                    {`${t('loyalty::admin_points.card.change')}…`}
                                </Button>
                            </CardAction>
                        ) : null}
                    </CardHeader>
                    <CardContent className="px-6">
                        <Description
                            items={[
                                { title: t('loyalty::admin_points.card.points'), content: <Points value={points} className="text-heading-24 text-ink" />, 'data-test': 'points' },
                                { title: t('loyalty::admin_points.card.worth'), content: <Amount value={worth} /> },
                                {
                                    title: t('loyalty::admin_points.card.expiring'),
                                    content:
                                        expiring === null
                                            ? t('loyalty::admin_points.card.none_expiring')
                                            : t('loyalty::admin_points.card.expiring_line', { points: figure(locale, expiring.points), day: day(expiring.day) }),
                                },
                            ]}
                        />
                    </CardContent>
                </Card>

                {nothingHere ? (
                    <Empty className="material-base" data-test="customer-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('loyalty::admin_points.empty.customer_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::admin_points.empty.customer_body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <LotsTable lots={lots} caption={t('loyalty::admin_points.live')} pointsLabel={t('loyalty::admin_points.columns.points')} expiresLabel={t('loyalty::admin_points.columns.expires')} />

                        <section className="grid gap-3" data-test="history">
                            <h2 className="text-heading-16 text-ink">{t('loyalty::admin_points.history')}</h2>
                            <StaffHistory rows={list.rows} />
                            {nextAt !== null && nextId !== null ? (
                                <LoadMoreButton loading={list.loading} onClick={() => list.more(address, { store: storeCode, before_at: nextAt, before_id: nextId }, ['history', 'nextAt', 'nextId'])} />
                            ) : null}
                        </section>
                    </>
                )}
            </div>

            {mayChangePoints ? (
                <ChangePointsDialog
                    open={changing}
                    onOpenChange={setChanging}
                    customerId={customer.id}
                    storeCode={storeCode}
                    storeName={storeName}
                    balance={points}
                    mostByHand={mostByHand}
                />
            ) : null}
        </AdminLayout>
    );
}
