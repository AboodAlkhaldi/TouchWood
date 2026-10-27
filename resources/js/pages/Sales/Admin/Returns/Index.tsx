import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { W } from '@/components/sales/admin';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import type { ReturnRowData, ReturnsPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { CustomerName } from '../Orders/Index';

/*
| P5 - Sales › Returns (sales.md §4.1): the reader's stores, or one, by state - Requested first (the owner's
| R2-38) - newest asked first, 50 at a time with Show More. A table on a computer, a card each on a phone.
*/

const R = 'sales::admin_returns';
const LIST = '/admin/returns';

export default function Index(page: ReturnsPage) {
    const t = useTranslator();
    const list = useLoadMore<ReturnRowData>(page.returns, (row) => row.id);
    const allStores = page.storeCode === null;
    const query = (extra: Record<string, string>) => ({ ...(page.storeCode === null ? {} : { store: page.storeCode }), tab: page.tab, ...extra });

    return (
        <AdminLayout title={t(`${R}.returns.title`)} subtitle={t(`${R}.returns.subtitle`)}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-end">
                    <Tabs value={page.tab} onValueChange={(next) => router.get(LIST, { ...query({}), tab: next }, { preserveState: true })}>
                        <TabsList className="max-w-full min-w-0 justify-start overflow-x-auto" aria-label={t(`${R}.returns.tabs_label`)} data-test="return-tabs">
                            {page.tabs.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`}>
                                    {t(`${R}.tab.${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                    <StoreFilter stores={page.stores} value={page.storeCode} all />
                </div>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="returns-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${R}.returns.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden overflow-x-auto md:block">
                            <Table>
                                <TableCaption className="sr-only">{t(`${R}.returns.title`)}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t(`${R}.returns.order`)}</TableHead>
                                        <TableHead>{t(`${R}.returns.requested`)}</TableHead>
                                        <TableHead>{t(`${R}.returns.customer`)}</TableHead>
                                        {allStores ? <TableHead>{t(`${W}.orders.store`)}</TableHead> : null}
                                        <TableHead>{t(`${R}.returns.state`)}</TableHead>
                                        <TableHead className="text-end">{t(`${R}.returns.refund`)}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} data-test={`return-${row.id}`}>
                                            <TableCell>
                                                <Link href={`${LIST}/${row.id}`} className="tw-figure text-label-14 text-ink underline-offset-2 hover:underline">
                                                    <bdi dir="ltr">{row.orderNumber}</bdi>
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-copy-13 text-ink-muted">
                                                <Time value={row.requestedAt} />
                                            </TableCell>
                                            <TableCell className="text-copy-14">
                                                <CustomerName customer={row.customer} />
                                            </TableCell>
                                            {allStores ? <TableCell className="text-copy-13 uppercase">{row.storeCode}</TableCell> : null}
                                            <TableCell>
                                                <Badge className={tone('gray-subtle')}>{t(`${W}.return_status.${row.status}`)}</Badge>
                                            </TableCell>
                                            <TableCell className="text-end">{row.refundDue === null ? null : <Money value={row.refundDue} />}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <ul className="grid gap-2 md:hidden" data-test="return-cards">
                            {list.rows.map((row) => (
                                <li key={row.id}>
                                    <Link href={`${LIST}/${row.id}`} className="material-small grid gap-1 bg-surface px-3 py-2" data-test={`return-card-${row.id}`}>
                                        <span className="flex items-baseline justify-between gap-2">
                                            <bdi dir="ltr" className="tw-figure text-label-14 text-ink">
                                                {row.orderNumber}
                                            </bdi>
                                            {row.refundDue === null ? null : <Money value={row.refundDue} className="text-label-14" />}
                                        </span>
                                        <span className="text-copy-13 text-ink-muted">
                                            <CustomerName customer={row.customer} /> · <Time value={row.requestedAt} focusable={false} />
                                        </span>
                                        <Badge className={`${tone('gray-subtle')} w-fit`}>{t(`${W}.return_status.${row.status}`)}</Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, query({ after: page.next ?? '' }), ['returns', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}
