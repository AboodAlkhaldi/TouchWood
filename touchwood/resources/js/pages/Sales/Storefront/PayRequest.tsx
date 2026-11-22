import { Suspense, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { Note } from '@/components/Note';
import { useClock } from '@/components/sales/payment/flow';
import { ClosedStore, PaymentBlock, PaymentInTheWay } from '@/components/sales/payment/lazy';
import { useLocale } from '@/components/sales/shop';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { isolate } from '@/lib/bidi';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CheckoutTryData, PayRequestPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| A request's page (payments.md §4.2, Q12): the order's number, its lines and total in short, the amount asked, until when,
| and checkout's own form, timers and answers, with Pay under the form. Paid goes back to the order. Declined or out of
| time, the form again while the request lasts. No request open: the link has ended, with a way to the order. A payment
| still open on it: a note and no form, coming back by itself once that payment is answered.
*/

const P = 'sales::shop_payment';

export default function PayRequest({ order, payment }: PayRequestPage) {
    const t = useTranslator();
    const link = useLink();
    const clock = useClock();
    const locale = useLocale();
    const press = useRef<(() => void) | null>(null);
    const [online, setOnline] = useState({ ready: false, locked: false, failed: false });
    const [closed, setClosed] = useState<CheckoutTryData | null>(null);
    const request = order.paymentRequest;
    const toOrder = link('storefront.sales.order', { number: order.number });

    if (closed !== null) {
        return (
            <Suspense fallback={null}>
                <ClosedStore view={closed} />
            </Suspense>
        );
    }

    const title = t(`${P}.request.title`, { number: isolate(order.number) });

    if (request === null) {
        return (
            <StorefrontLayout title={title}>
                <Card className="material-base mx-auto w-full max-w-xl gap-3 border-0 py-6" data-test="request-ended">
                    <CardContent className="grid justify-items-center gap-3 px-6 text-center">
                        <p className="text-copy-14 text-ink">{t(`${P}.request.ended`)}</p>
                        <Button asChild variant="outline">
                            <Link href={toOrder} data-test="request-to-order">
                                {t(`${P}.request.to_order`)}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            </StorefrontLayout>
        );
    }

    const reason = online.failed ? t(`${P}.request.form_failed`) : !online.ready && !online.locked ? t(`${P}.loading`) : undefined;

    return (
        <StorefrontLayout title={title}>
            <div className="mx-auto grid w-full max-w-xl min-w-0 grid-cols-[minmax(0,1fr)] gap-4">
                <FormError />
                {order.isTest ? (
                    <Note variant="warning" data-test="request-test-note">
                        {t(`${P}.test_note`)}
                    </Note>
                ) : null}
                <h1 className="text-heading-24 text-ink">{title}</h1>
                {payment === null ? null : (
                    <Suspense fallback={null}>
                        <PaymentInTheWay payment={payment} number={order.number} onClosed={setClosed} />
                    </Suspense>
                )}
                <Card className="material-base gap-3 border-0 py-4" data-test="request-order">
                    <CardHeader className="px-4">
                        <CardTitle className="text-heading-16">{t(`${P}.request.order`)}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 px-4">
                        <ul className="grid gap-1 text-copy-14 text-ink">
                            {order.lines.map((line) => (
                                <li key={line.id} className="flex items-baseline justify-between gap-3">
                                    <span className="min-w-0">
                                        {line.name}
                                        {line.values === '' ? null : <span className="text-ink-muted"> · {line.values}</span>}
                                    </span>
                                    <span className="tw-figure shrink-0 text-ink-muted" dir="ltr">
                                        × {figure(locale, line.quantity)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <dl className="grid gap-1 border-t border-line pt-3 text-copy-14">
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-ink-muted">{t(`${P}.request.total`)}</dt>
                                <dd>
                                    <Money value={order.total} className="text-ink" />
                                </dd>
                            </div>
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-label-14 text-ink">{t(`${P}.request.asked`)}</dt>
                                <dd data-test="request-amount">
                                    <Money value={request.amount} className="text-label-16 text-ink" />
                                </dd>
                            </div>
                        </dl>
                        <p className="text-copy-13 text-ink-muted" data-test="request-until">
                            {t(`${P}.request.until`, { time: isolate(clock(request.expiresAt)) })}
                        </p>
                    </CardContent>
                </Card>
                {payment !== null ? null : (
                    <Card className="material-base gap-3 border-0 py-4" data-test="request-payment">
                        <CardHeader className="px-4">
                            <CardTitle className="text-heading-16">{t(`${P}.request.payment`)}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 px-4">
                            <Suspense fallback={<p className="text-copy-14 text-ink-muted">{t(`${P}.loading`)}</p>}>
                                <PaymentBlock number={order.number} press={press} onState={setOnline} onClosed={setClosed} />
                            </Suspense>
                            <ActionButton size="lg" loading={online.locked} disabledReason={reason} onClick={() => press.current?.()} data-test="pay">
                                {t(`${P}.pay`, { total: request.amount.text })}
                            </ActionButton>
                        </CardContent>
                    </Card>
                )}
                <Link href={toOrder} className="justify-self-start text-copy-14 text-ink underline underline-offset-4" data-test="request-back">
                    {t(`${P}.request.to_order`)}
                </Link>
            </div>
        </StorefrontLayout>
    );
}
