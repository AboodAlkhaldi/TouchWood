import { Link } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { Time } from '@/components/Time';
import { O, OrderStatus, plural, useLocale } from '@/components/sales/shop';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { MyOrdersPage, OwnOrderRowData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| S7 - My Orders (sales.md §4.1), a page of the account beside its tabs, always offered: newest first, 20 then
| Show More; each order its number, date, status, total and pieces, opening its page; no filter (R1-27).
*/

export default function MyOrders(page: MyOrdersPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const list = useLoadMore<OwnOrderRowData>(page.orders, (row) => row.number);

    return (
        <AccountLayout title={t(`${O}.my.title`)} subtitle={t(`${O}.my.subtitle`)} page="sales.orders">
            <div className="grid min-w-0 content-start gap-3">
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="my-orders-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${O}.my.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    // One bordered list, as the cart's and the order's lines (shop-design.md §15.3).
                    <ul className="grid divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface" data-test="my-orders">
                        {list.rows.map((order) => (
                            <li key={order.number}>
                                <Link href={link('storefront.sales.order', { number: order.number })} className="grid gap-1 px-4 py-3.5 hover:bg-surface-sunken" data-test={`my-order-${order.number}`}>
                                    <span className="flex flex-wrap items-baseline justify-between gap-2">
                                        <bdi dir="ltr" className="tw-figure text-label-16 font-semibold text-brand">
                                            {order.number}
                                        </bdi>
                                        <span>
                                            <Money value={order.total} className="text-label-14 font-semibold text-ink" />
                                            {order.shippingPending ? (
                                                <span className="text-copy-13 text-ink-muted" data-test="before-shipping">
                                                    {' '}
                                                    {t(`${O}.order.before_shipping`)}
                                                </span>
                                            ) : null}
                                        </span>
                                    </span>
                                    <span className="flex flex-wrap items-center justify-between gap-2 text-copy-13 text-ink-muted">
                                        <span>
                                            <Time value={order.placedAt} focusable={false} /> · {plural(t, locale, `${O}.my.pieces`, order.pieces)}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-2">
                                            {order.paymentRequested ? (
                                                <Badge variant="outline" data-test="payment-requested">
                                                    {t(`${O}.my.payment_requested`)}
                                                </Badge>
                                            ) : null}
                                            <OrderStatus status={order.status} label={t(`${O}.status.${order.status}`)} />
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {page.next !== null ? <LoadMoreButton shop loading={list.loading} onClick={() => list.more(link('storefront.sales.orders'), { after: page.next ?? '' }, ['orders', 'next'])} /> : null}
            </div>
        </AccountLayout>
    );
}
