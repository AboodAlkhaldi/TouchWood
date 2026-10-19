import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { Amount, Points, ProgrammeOffNote } from '@/components/loyalty/parts';
import type { RedemptionsListPage } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';

/*
| L4 - Points › Redemptions (loyalty.md §4.1): every order in the store that used points, newest first,
| 50 at a time with Show More; no filters and no totals (answer 6).
|
| The order's public number is plain text until Sales's screens can open an order (answer 12). The
| customer is named - a customer registered in another store too, the name only (answer 7; §9.5 P1)
| - and opens their points here; a deleted customer has no name and no link.
*/

const LIST = '/admin/points/redemptions';

export default function Redemptions({ redemptions, nextAt, nextId, storeCode, programmeOn, stores }: RedemptionsListPage) {
    const t = useTranslator();
    const list = useLoadMore(redemptions, (row) => String(row.id));

    return (
        <AdminLayout title={t('loyalty::admin_points.redemptions.title')} subtitle={t('loyalty::admin_points.redemptions.subtitle')}>
            <div className="grid gap-4">
                <FormError />
                <ProgrammeOffNote on={programmeOn} />
                <StoreFilter stores={stores} value={storeCode} className="max-w-xs" />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="redemptions-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('loyalty::admin_points.empty.redemptions_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::admin_points.empty.redemptions_body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('loyalty::admin_points.redemptions.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('loyalty::admin_points.columns.date')}</TableHead>
                                    <TableHead>{t('loyalty::admin_points.columns.order')}</TableHead>
                                    <TableHead>{t('loyalty::admin_points.columns.customer')}</TableHead>
                                    <TableHead className="text-end">{t('loyalty::admin_points.columns.points')}</TableHead>
                                    <TableHead className="text-end">{t('loyalty::admin_points.columns.discount')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((row) => (
                                    <TableRow key={row.id} data-test={`redemption-${row.id}`}>
                                        <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                                            <Time value={row.occurredAt} />
                                        </TableCell>
                                        <TableCell>
                                            <bdi dir="ltr" className="tw-figure">
                                                {row.orderNumber}
                                            </bdi>
                                        </TableCell>
                                        <TableCell>
                                            {row.customer.deleted ? (
                                                <span className="text-ink-muted">{t('loyalty::admin_points.deleted_customer')}</span>
                                            ) : (
                                                <Link href={`/admin/points/customers/${row.customer.id}?store=${encodeURIComponent(storeCode)}`} className="text-ink underline-offset-4 hover:underline">
                                                    <bdi>{row.customer.name}</bdi>
                                                </Link>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <Points value={row.points} />
                                        </TableCell>
                                        <TableCell className="text-end whitespace-nowrap">
                                            <Amount value={row.discount} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {nextAt !== null && nextId !== null ? (
                    <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: storeCode, before_at: nextAt, before_id: nextId }, ['redemptions', 'nextAt', 'nextId'])} />
                ) : null}
            </div>
        </AdminLayout>
    );
}
