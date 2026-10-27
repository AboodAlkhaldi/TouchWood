import { Link } from '@inertiajs/react';
import { Money } from '@/components/Money';
import { Time } from '@/components/Time';
import { StateBadges, W } from '@/components/sales/admin';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { CustomerOrdersSectionData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| P7 - a customer's orders on the staff customer page (sales.md §4.1; access.md amendment 72(a)): their 10
| latest orders in the reader's stores, newest first, each opening its page - the number, when, the store, its
| states and total - and "See all of this customer's orders", the orders list for this customer alone. Sales
| sent nothing for a reader who views no orders: the section is not drawn at all.
*/

export function Section({ data }: { data: Record<string, unknown> }) {
    const t = useTranslator();
    // Sales's own shape (OrdersCustomerSection): Access hands it over as it came.
    const section = data as unknown as CustomerOrdersSectionData;

    return (
        <section className="grid gap-3" data-test="orders-section">
            <h2 className="text-heading-16 text-ink">{t(`${W}.section.title`)}</h2>

            {section.orders.length === 0 ? (
                <p className="text-copy-14 text-ink-muted" data-test="orders-none">
                    {t(`${W}.section.none`)}
                </p>
            ) : (
                <>
                    <ul className="grid gap-2">
                        {section.orders.map((order) => (
                            <li key={order.id}>
                                <Link href={`/admin/orders/${order.id}`} className="material-small grid gap-1 bg-surface px-3 py-2" data-test={`customer-order-${order.number}`}>
                                    <span className="flex items-baseline justify-between gap-2">
                                        <bdi dir="ltr" className="tw-figure text-label-14 text-ink">
                                            {order.number}
                                        </bdi>
                                        <Money value={order.total} className="text-label-14" />
                                    </span>
                                    <span className="text-copy-13 text-ink-muted">
                                        <Time value={order.placedAt} focusable={false} />
                                        <span className="uppercase"> · {order.storeCode}</span>
                                    </span>
                                    <StateBadges status={order.status} payment={order.payment} shipment={order.shipment} short={order.shortOfStock} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div>
                        <Button asChild variant="outline" size="sm">
                            <Link href={`/admin/orders?customer=${encodeURIComponent(section.customerId)}`} data-test="see-all-orders">
                                {t(`${W}.section.see_all`)}
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </section>
    );
}
