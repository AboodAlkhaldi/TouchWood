import { Head, Link, usePage } from '@inertiajs/react';
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
| a tax invoice - one slip, with the codes, for the warehouse (the owner's R1-47). The browser's own print; the
| page's frame and buttons are left off the paper.
*/

export default function Print(slip: PrintPage) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const placed = new Intl.DateTimeFormat(intlLocale(locale), { dateStyle: 'medium', timeStyle: 'short', timeZone: slip.storeTimezone }).format(new Date(slip.placedAt));
    const back = typeof window === 'undefined' ? '/admin/orders' : window.location.pathname.replace(/\/print$/u, '');
    const rows: [string, PrintPage['total'], boolean][] = [
        [t(`${W}.show.subtotal`), slip.grossSubtotal, true],
        [t(`${W}.show.coupon`, { code: '' }), slip.couponDiscount, false],
        [t(`${W}.show.points`, { points: '' }), slip.pointsDiscount, false],
        [t(`${W}.show.shipping`), slip.shipping, true],
        [`${t(`${W}.show.vat`)} (${figure('en', slip.vatRateBasisPoints / 100)}%)`, slip.vat, true],
    ];

    return (
        <main className="mx-auto grid max-w-3xl gap-6 bg-surface p-8 text-ink print:p-0">
            <Head title={t(`${W}.print.title`, { number: slip.number })} />
            <div className="flex items-center justify-between gap-3 print:hidden">
                <Link href={back} className="text-copy-14 underline underline-offset-4" data-test="print-back">
                    {t(`${W}.print.back`)}
                </Link>
                <Button type="button" onClick={() => window.print()} data-test="print-now">
                    {t(`${W}.print.print`)}
                </Button>
            </div>

            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-4">
                <div className="grid gap-1">
                    <h1 className="text-heading-24">{t(`${W}.print.title`, { number: slip.number })}</h1>
                    <p className="text-copy-14 text-ink-muted">
                        {slip.storeName} · {placed}
                    </p>
                </div>
                <div className="grid gap-0.5 text-copy-14">
                    <span className="text-label-14">{slip.contactName}</span>
                    <bdi dir="ltr" className="tw-figure">
                        {slip.contactPhone}
                    </bdi>
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
                    .map(([label, value]) => (
                        <div key={label} className="flex justify-between gap-3">
                            <dt className="text-ink-muted">{label}</dt>
                            <dd>
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
        </main>
    );
}
