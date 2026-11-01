import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { SearchField } from '@/components/SearchField';
import { Money } from '@/components/Money';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { StateBadges, W } from '@/components/sales/admin';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { OrderCustomerData, OrderRowData, OrdersPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| P1 - Sales › Orders (sales.md §4.1): the reader's stores, or one, by tab - the work to do first - newest
| first, 50 at a time with Show More. A table on a computer; on a phone each order a card (the owner's R2-34),
| for a table this wide cannot fit. Opening an order is its number. One box searches by the order number, the
| customer's name or a phone (R1-37); "See all" from a customer's page lists that customer's orders alone.
*/

const LIST = '/admin/orders';

export default function Index(page: OrdersPage) {
    const t = useTranslator();
    const list = useLoadMore<OrderRowData>(page.orders, (row) => row.id);
    const allStores = page.storeCode === null;
    const [q, setQ] = useState(page.q);
    const kept = { ...(page.storeCode === null ? {} : { store: page.storeCode }), tab: page.tab, ...(page.customerId === null ? {} : { customer: page.customerId }) };
    const query = (extra: Record<string, string>) => ({ ...kept, ...(page.q === '' ? {} : { q: page.q }), ...extra });
    const search = (next: string) => router.get(LIST, next.trim() === '' ? kept : { ...kept, q: next.trim() }, { preserveState: true });

    return (
        <AdminLayout title={t(`${W}.orders.title`)} subtitle={t(`${W}.orders.subtitle`)}>
            {/* One column no wider than the page: seven tabs scroll inside their bar on a phone. */}
            <div className="grid grid-cols-[minmax(0,1fr)] gap-4">
                <FormError />
                {page.customerId === null ? null : (
                    <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-copy-14 text-ink" data-test="customer-only">
                        <span className="text-label-14">{page.customerName === null ? t(`${W}.orders.of_deleted`) : t(`${W}.orders.of_customer`, { name: page.customerName })}</span>
                        <Link href={LIST} className="text-ink-muted underline underline-offset-4" data-test="every-customer">
                            {t(`${W}.orders.every_customer`)}
                        </Link>
                    </p>
                )}
                <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-end">
                    <form
                        role="search"
                        onSubmit={(event) => {
                            event.preventDefault();
                            search(q);
                        }}
                    >
                        <SearchField
                            id="order-search"
                            label={t(`${W}.orders.search`)}
                            placeholder={t(`${W}.orders.search_placeholder`)}
                            value={q}
                            onValueChange={setQ}
                            onClear={() => search('')}
                            data-test="order-search"
                        />
                    </form>
                    <StoreFilter stores={page.stores} value={page.storeCode} all />
                </div>
                {page.customerId !== null ? null : (
                    <Tabs value={page.tab} onValueChange={(next) => router.get(LIST, { ...query({}), tab: next }, { preserveState: true })}>
                        <TabsList className="max-w-full min-w-0 justify-start overflow-x-auto" aria-label={t(`${W}.orders.tabs_label`)} data-test="order-tabs">
                            {page.tabs.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`}>
                                    {t(`${W}.tab.${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                )}

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="orders-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{page.q === '' ? t(`${W}.orders.empty`) : t(`${W}.orders.empty_search`, { q: page.q })}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden overflow-x-auto md:block">
                            <Table>
                                <TableCaption className="sr-only">{t(`${W}.orders.title`)}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t(`${W}.orders.number`)}</TableHead>
                                        <TableHead>{t(`${W}.orders.placed`)}</TableHead>
                                        <TableHead>{t(`${W}.orders.customer`)}</TableHead>
                                        {allStores ? <TableHead>{t(`${W}.orders.store`)}</TableHead> : null}
                                        <TableHead>{t(`${W}.orders.state`)}</TableHead>
                                        <TableHead className="text-end">{t(`${W}.orders.total`)}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((order) => (
                                        <TableRow key={order.id} data-test={`order-${order.number}`}>
                                            <TableCell>
                                                <Link href={`${LIST}/${order.id}`} className="tw-figure text-label-14 text-ink underline-offset-2 hover:underline">
                                                    <bdi dir="ltr">{order.number}</bdi>
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-copy-13 text-ink-muted">
                                                <Time value={order.placedAt} />
                                            </TableCell>
                                            <TableCell className="text-copy-14">
                                                <CustomerName customer={order.customer} />
                                            </TableCell>
                                            {allStores ? <TableCell className="text-copy-13 uppercase">{order.storeCode}</TableCell> : null}
                                            <TableCell>
                                                <StateBadges status={order.status} payment={order.payment} shipment={order.shipment} short={order.shortOfStock} fee={order.shippingFeePending ? t(`${W}.orders.fee_pending`) : null} />
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <Money value={order.total} />
                                                {order.shippingFeePending ? <span className="block text-copy-13 text-ink-muted">{t(`${W}.orders.before_shipping`)}</span> : null}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <ul className="grid gap-2 md:hidden" data-test="order-cards">
                            {list.rows.map((order) => (
                                <li key={order.id}>
                                    <Link href={`${LIST}/${order.id}`} className="material-small grid gap-1 bg-surface px-3 py-2" data-test={`order-card-${order.number}`}>
                                        <span className="flex items-baseline justify-between gap-2">
                                            <bdi dir="ltr" className="tw-figure text-label-14 text-ink">
                                                {order.number}
                                            </bdi>
                                            <span className="text-end">
                                                <Money value={order.total} className="text-label-14" />
                                                {order.shippingFeePending ? <span className="block text-copy-13 text-ink-muted">{t(`${W}.orders.before_shipping`)}</span> : null}
                                            </span>
                                        </span>
                                        <span className="text-copy-13 text-ink-muted">
                                            <CustomerName customer={order.customer} /> · <Time value={order.placedAt} focusable={false} />
                                            {allStores ? <span className="uppercase"> · {order.storeCode}</span> : null}
                                        </span>
                                        <StateBadges status={order.status} payment={order.payment} shipment={order.shipment} short={order.shortOfStock} fee={order.shippingFeePending ? t(`${W}.orders.fee_pending`) : null} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, query({ after: page.next ?? '' }), ['orders', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}

export function CustomerName({ customer }: { customer: OrderCustomerData }) {
    const t = useTranslator();

    return customer.deleted || customer.name === null ? <span className="text-ink-muted">{t(`${W}.deleted_customer`)}</span> : <>{customer.name}</>;
}
