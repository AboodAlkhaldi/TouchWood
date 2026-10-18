import { usePage } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AccountLayout } from '@/layouts/AccountLayout';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { formatMoney } from '@/lib/money';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { LotsTable, Points as PointsFigure, SignedPoints, useDay } from '@/pages/Loyalty/Admin/Points/parts';
import type { HistoryRowData, MyPointsPage } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| L7 - the customer's own points in this store (loyalty.md §4.1), a page of their account beside its
| own tabs (access.md amendment 50), offered while the store's programme is on or they have points
| here (answer 4).
|
| The balance and what it is worth, what expires next (answer 11), every live lot, and the history in
| the customer's own words (answer 10) - never a reason or a staff name, which the read never gives a
| customer. The "how it works" line (§9.5 P3) only while the programme is on; while it is off, a note
| says the points are paused and stay until they expire.
*/

export default function Points({ points, worth, expiring, lots, history, nextAt, nextId, programmeOn, earnPerUnit, pointsPerUnitOff, unit }: MyPointsPage) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const day = useDay();
    const list = useLoadMore(history, (row) => String(row.id));
    const nothing = lots.length === 0 && list.rows.length === 0;
    const unitText = formatMoney(unit, locale);

    return (
        <AccountLayout title={t('loyalty::shop_points.title')} subtitle={t('loyalty::shop_points.subtitle')} page="loyalty.points">
            <div className="grid min-w-0 content-start gap-6">
                {programmeOn ? null : (
                    <Note variant="secondary" data-test="paused">
                        {t('loyalty::shop_points.paused')}
                    </Note>
                )}

                <Card className="gap-3 py-6" data-test="balance-card">
                    <CardHeader className="px-6">
                        <CardTitle className="text-copy-14 text-ink-muted">
                            <h2>{t('loyalty::shop_points.balance')}</h2>
                        </CardTitle>
                        <CardDescription className="grid gap-1">
                            <PointsFigure value={points} className="text-heading-32 text-ink" />
                            <span className="text-copy-14 text-ink-muted">
                                {t('loyalty::shop_points.worth', { amount: formatMoney(worth, locale) })}
                            </span>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-2 px-6 text-copy-14 text-ink-muted">
                        <p data-test="expiring">
                            {expiring === null
                                ? t('loyalty::shop_points.none_expiring')
                                : t('loyalty::shop_points.expiring_line', { points: figure(locale, expiring.points), day: day(expiring.day) })}
                        </p>
                        {programmeOn ? (
                            <p data-test="how-it-works">
                                {t('loyalty::shop_points.how_it_works', { earn: figure(locale, earnPerUnit), off: figure(locale, pointsPerUnitOff), unit: unitText })}
                            </p>
                        ) : null}
                    </CardContent>
                </Card>

                {nothing ? (
                    <Empty className="border" data-test="points-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('loyalty::shop_points.empty_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::shop_points.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <LotsTable lots={lots} caption={t('loyalty::shop_points.live')} pointsLabel={t('loyalty::shop_points.columns.points')} expiresLabel={t('loyalty::shop_points.columns.expires')} />

                        <section className="grid min-w-0 gap-3" data-test="history">
                            <h2 className="text-heading-16 text-ink">{t('loyalty::shop_points.history')}</h2>
                            <div className="max-w-full overflow-x-auto rounded-[var(--tw-radius)] border">
                                <Table>
                                    <TableCaption className="sr-only">{t('loyalty::shop_points.history')}</TableCaption>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('loyalty::shop_points.columns.date')}</TableHead>
                                            <TableHead>{t('loyalty::shop_points.columns.what')}</TableHead>
                                            <TableHead className="text-end">{t('loyalty::shop_points.columns.points')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {list.rows.map((row) => (
                                            <TableRow key={row.id} data-test={`history-${row.id}`}>
                                                <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                                                    <Time value={row.occurredAt} />
                                                </TableCell>
                                                <TableCell>
                                                    <What row={row} />
                                                </TableCell>
                                                <TableCell className="text-end">
                                                    <SignedPoints value={row.points} />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            {nextAt !== null && nextId !== null ? (
                                <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { before_at: nextAt, before_id: nextId }, ['history', 'nextAt', 'nextId'])} />
                            ) : null}
                        </section>
                    </>
                )}
            </div>
        </AccountLayout>
    );
}

/** What happened, in the customer's words, with the order's number where there is one (answer 10). */
function What({ row }: { row: HistoryRowData }) {
    const t = useTranslator();

    return <>{t(`loyalty::shop_points.kind.${row.kind}`, { order: row.orderNumber === null ? '—' : isolate(row.orderNumber) })}</>;
}

