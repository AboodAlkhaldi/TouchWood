import { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { plural, useLocale } from '@/components/sales/shop';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { PlacedPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import { Bank } from './Checkout';

/*
| S6 - the order placed (sales.md §4.1; R1-26, R2-18): its number and total, what happens next, and for a bank
| transfer the store's details again with an Upload Transfer Receipt box - up to three files, a PDF or a photo
| (§1.9); and the link to the order.
*/

const W = 'sales::shop_checkout';

export default function Placed(page: PlacedPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <StorefrontLayout title={t(`${W}.placed.title`)}>
            <div className="mx-auto grid w-full max-w-xl grid-cols-[minmax(0,1fr)] gap-4">
                <FormError />
                <Card className="material-base gap-3 border-0 py-6" data-test="order-placed">
                    <CardContent className="grid justify-items-center gap-3 px-6 text-center">
                        <CircleCheck aria-hidden="true" className="size-10 text-ink" />
                        <h1 className="text-heading-24 text-ink">{t(`${W}.placed.title`)}</h1>
                        <p className="text-label-16 text-ink">
                            {t(`${W}.placed.number`, { number: '' })}
                            <bdi dir="ltr" className="tw-figure">
                                {page.number}
                            </bdi>{' '}
                            · <Money value={page.total} />
                        </p>
                        <p className="text-copy-14 text-ink-muted">{t(`${W}.placed.next`)}</p>
                        <Link href={link('storefront.sales.order', { number: page.number })} className="text-copy-14 text-ink underline underline-offset-4" data-test="view-order">
                            {t(`${W}.placed.view`)}
                        </Link>
                    </CardContent>
                </Card>

                {page.wayToPay === 'BANK_TRANSFER' ? (
                    <Card className="material-base gap-3 border-0 py-4" data-test="transfer-receipt">
                        <CardContent className="grid gap-3 px-4">
                            {page.bank === null ? null : <Bank bank={page.bank} />}
                            <p className="text-copy-14 text-ink">{t(`${W}.placed.upload`)}</p>
                            <p className="text-copy-13 text-ink-muted">{plural(t, locale, `${W}.placed.receipts`, page.receipts)}</p>
                            {page.mayUpload ? (
                                <form
                                    className="grid gap-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();

                                        if (file === null) {
                                            input.current?.focus();

                                            return;
                                        }

                                        router.post(link('storefront.sales.receipt', { number: page.number }), { file } as never, {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onStart: () => setBusy(true),
                                            onFinish: () => setBusy(false),
                                            onSuccess: () => {
                                                setFile(null);

                                                if (input.current) input.current.value = '';
                                            },
                                        });
                                    }}
                                >
                                    <Label htmlFor="receipt-file">{t(`${W}.placed.file`)}</Label>
                                    <Input id="receipt-file" ref={input} type="file" accept="application/pdf,image/jpeg,image/png" onChange={(event) => setFile(event.target.files?.[0] ?? null)} data-test="receipt-file" />
                                    <ActionButton type="submit" className="w-fit" loading={busy} disabledReason={file === null ? t(`${W}.placed.no_file`) : undefined} data-test="upload-receipt">
                                        {t(`${W}.placed.send`)}
                                    </ActionButton>
                                </form>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </StorefrontLayout>
    );
}
