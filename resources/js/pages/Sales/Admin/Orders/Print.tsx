import { usePage } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { AddressLines } from '@/components/AddressLines';
import { Money } from '@/components/Money';
import { W } from '@/components/sales/admin';
import { Button } from '@/components/ui/button';
import { figure, intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { PrintPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| P3 - an order's packing slip (sales.md §4.1; handoff §12.6): an order confirmation and packing document, never
| a tax invoice - one slip, with the codes, for the warehouse (the owner's R1-47). The browser's own print, in the
| panel's frame like every page; the frame and the Print button are left off the paper (app.css).
*/

export default function Print(slip: PrintPage) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    // Someone other than the account holder receives: the slip names whose phone it is.
    const other = slip.recipientName !== '' && slip.recipientName !== slip.contactName;
    const placed = new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeStyle: 'short', timeZone: slip.storeTimezone }).format(new Date(slip.placedAt));
    // [label, amount, shown at 0, taken off]: a summary line with nothing in it is not shown (R2-15).
    const rows: [string, PrintPage['total'], boolean, boolean][] = [
        [t(`${W}.show.subtotal`), slip.grossSubtotal, true, false],
        [t(`${W}.show.savings`), slip.savings, false, true],
        [t(`${W}.print.coupon`), slip.couponDiscount, false, true],
        [t(`${W}.print.points`), slip.pointsDiscount, false, true],
        [t(`${W}.show.shipping`), slip.shipping, true, false],
        [`${t(`${W}.show.vat`)} (${figure('en', slip.vatRateBasisPoints / 100)}%)`, slip.vat, true, false],
    ];

    return (
        <AdminLayout
            title={t(`${W}.print.title`, { number: slip.number })}
            subtitle={`${slip.storeName} · ${placed}`}
            breadcrumbs={[{ label: t(`${W}.orders.title`), href: '/admin/orders' }, { label: slip.number, href: `/admin/orders/${slip.id}` }]}
            action={
                <Button type="button" className="print:hidden" onClick={() => window.print()} data-test="print-now">
                    {t(`${W}.print.print`)}
                </Button>
            }
        >
            <div className="grid max-w-3xl gap-6 text-ink print:bg-white print:text-black">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-4">
                    <div className="grid gap-0.5 text-copy-14">
                        <span className="text-label-14">{slip.recipientName === '' ? slip.contactName : slip.recipientName}</span>
                        {/* The courier calls the account holder (access.md amendment 89): named beside the number when someone else receives. */}
                        <span data-test="slip-phone">
                            {t(`${W}.print.account_phone`)}:{' '}
                            {other ? (
                                <>
                                    <bdi>{slip.contactName}</bdi> ·{' '}
                                </>
                            ) : null}
                            <bdi dir="ltr" className="tw-figure">
                                {slip.contactPhone}
                            </bdi>
                        </span>
                        <AddressLines text={slip.address} />
                    </div>
                </header>

                <table className="w-full text-copy-14">
                    <thead>
                        <tr className="border-b border-line text-start text-ink-muted">
                            <th className="py-2 text-start font-normal">{t(`${W}.show.lines`)}</th>
                            <th className="py-2 text-start font-normal">{t(`${W}.show.code`)}</th>
                            <th className="py-2 text-end font-normal">{t(`${W}.show.pieces`)}</th>
                            <th className="py-2 text-end font-normal">{t(`${W}.show.line_total`)}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {slip.lines.map((line) => (
                            <tr key={line.id} className="border-b border-line align-top" data-test="slip-line">
                                <td className="py-2">
                                    <span className="block">
                                        {line.gift ? `${t(`${W}.show.gift`)}: ` : ''}
                                        {line.name}
                                    </span>
                                    <span className="block text-copy-13 text-ink-muted">
                                        {line.values}
                                        {line.values === '' ? '' : ' · '}
                                        {t(`${W}.mode.${line.mode}`)}
                                    </span>
                                </td>
                                <td className="py-2">
                                    <bdi dir="ltr">{line.code}</bdi>
                                </td>
                                <td className="tw-figure py-2 text-end">{figure('en', line.quantity)}</td>
                                <td className="py-2 text-end">
                                    <Money value={line.total} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <dl className="ms-auto grid w-full max-w-xs gap-1 text-copy-14">
                    {rows
                        .filter(([, value, always]) => always || value.minor !== 0)
                        .map(([label, value, , minus]) => (
                            <div key={label} className="flex justify-between gap-3">
                                <dt className="text-ink-muted">{label}</dt>
                                <dd>
                                    {minus ? <span aria-hidden="true">− </span> : null}
                                    <Money value={value} />
                                </dd>
                            </div>
                        ))}
                    <div className="flex justify-between gap-3 border-t border-line pt-1 text-label-16">
                        <dt>{t(`${W}.show.total`)}</dt>
                        <dd>
                            <Money value={slip.total} />
                        </dd>
                    </div>
                </dl>
                <p className="text-copy-13 text-ink-muted">
                    {t(`${W}.show.way_to_pay`)}: {t(`${W}.way.${slip.wayToPay}`)}
                </p>
            </div>
        </AdminLayout>
    );
}
