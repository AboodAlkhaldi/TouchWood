import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus, X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { C, plural, Price, useLocale } from '@/components/sales/shop';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CartLineData, CartPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| S4 - the cart (sales.md §4.1): each line with its photo, name, size, Retail or Wholesale, today's price (the
| old one struck on a sale), − and + within its limits, its total and Remove (R1-11); a line with a problem
| says so with its one fix (R2-10); the lines that left at a sign-in named at the top, with Close (R2-8); the
| coupon kept for checkout, a guest asked to sign in for it (R1-15); the summary with Checkout - out of reach
| while a line is marked (R2-11), and for an account that may not order yet, saying so once (R1-17). A
| guest's Checkout signs in and comes straight back (R1-16). Empty, it says so with Continue Shopping (R2-9).
*/

export default function Cart(cart: CartPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const { shop } = usePage<SharedProps>().props;
    const home = shop ? `/${shop.code}/${locale}` : '/';

    return (
        <StorefrontLayout title={t(`${C}.title`)}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6">
                <h1 className="text-heading-24 text-ink">{t(`${C}.title`)}</h1>
                <FormError />
                {cart.notice.length > 0 ? <Notice cart={cart} /> : null}

                {cart.lines.length === 0 ? (
                    <Empty className="material-base" data-test="cart-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(`${C}.empty`)}</EmptyTitle>
                            <EmptyDescription className="sr-only">{t(`${C}.empty`)}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={home} data-test="continue-shopping">
                                    {t(`${C}.continue`)}
                                </Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <ul className="grid min-w-0 content-start gap-3" aria-label={t(`${C}.line.lines`)} data-test="cart-lines">
                            {cart.lines.map((line) => (
                                <Line key={`${line.variantId}-${line.mode}`} line={line} />
                            ))}
                        </ul>
                        <div className="grid min-w-0 content-start gap-4">
                            <Coupon code={cart.couponCode} />
                            <Summary cart={cart} checkout={link('storefront.sales.cart.sign-in')} />
                        </div>
                    </div>
                )}
            </div>
        </StorefrontLayout>
    );
}

function usePost() {
    const link = useLink();
    const [busy, setBusy] = useState(false);

    return {
        busy,
        post: (name: string, data: Record<string, unknown>) => router.post(link(name), data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) }),
    };
}

function Line({ line }: { line: CartLineData }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const { busy, post } = usePost();
    const key = { variant: line.variantId, mode: line.mode };
    const set = (quantity: number) => post('storefront.sales.cart.change', { ...key, quantity });
    const remove = () => post('storefront.sales.cart.remove', key);
    const fewest = Math.max(1, line.minimum);
    const most = line.maximum;
    const problem = line.problem;
    // Its one fix (R2-10): to the limit it passed, else off the cart.
    const fixTo = problem === 'BELOW_MINIMUM' ? line.minimum : problem === 'ABOVE_MAXIMUM' ? most : null;

    return (
        <li className="material-base grid grid-cols-[4.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[6rem_minmax(0,1fr)]" data-test={`cart-line-${line.code}`}>
            <ShopPhoto photo={line.photo as PhotoSizes | null} size="thumb" alt="" className="aspect-square w-full rounded-md object-cover" />
            <div className="grid min-w-0 gap-2">
                <div className="flex min-w-0 items-start justify-between gap-2">
                    <div className="grid min-w-0 gap-0.5">
                        {line.slug === null ? (
                            <span className="text-label-14 text-ink">{line.name}</span>
                        ) : (
                            <Link href={link('storefront.catalog.product', { slug: line.slug })} className="text-label-14 text-ink underline-offset-2 hover:underline">
                                {line.name}
                            </Link>
                        )}
                        {line.values === '' ? null : <span className="text-copy-13 text-ink-muted">{line.values}</span>}
                        <span className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                            <Badge variant="outline">{t(`${C}.mode.${line.mode}`)}</Badge>
                            <bdi dir="ltr" className="tw-figure">
                                {line.code}
                            </bdi>
                        </span>
                    </div>
                    <Button type="button" variant="ghost" size="icon" disabled={busy} onClick={remove} aria-label={`${t(`${C}.line.remove`)}: ${line.name}`} data-test="remove-line">
                        <X aria-hidden="true" />
                    </Button>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-1" role="group" aria-label={t(`${C}.line.quantity`, { name: line.name })}>
                        <Button type="button" variant="outline" size="icon" disabled={busy || line.quantity <= fewest} onClick={() => set(line.quantity - 1)} aria-label={t(`${C}.line.less`)} data-test="less">
                            <Minus aria-hidden="true" />
                        </Button>
                        <output className="tw-figure min-w-10 text-center text-label-14 text-ink" aria-live="polite" data-test="quantity">
                            {figure(locale, line.quantity)}
                        </output>
                        <Button type="button" variant="outline" size="icon" disabled={busy || (most !== null && line.quantity >= most)} onClick={() => set(line.quantity + 1)} aria-label={t(`${C}.line.more`)} data-test="more">
                            <Plus aria-hidden="true" />
                        </Button>
                    </div>
                    <div className="grid justify-items-end gap-0.5 text-end">
                        {line.total === null ? <span className="text-copy-14 text-ink-muted">{t(`${C}.line.no_price`)}</span> : <Money value={line.total} className="text-label-14 text-ink" />}
                        {line.unit === null ? null : (
                            <span className="text-copy-13 text-ink-muted">
                                <Price now={line.unit} before={line.before} /> {t(`${C}.line.unit`)}
                            </span>
                        )}
                    </div>
                </div>

                <p className="text-copy-12 text-ink-muted">
                    {most === null ? t(`${C}.line.from`, { minimum: figure(locale, fewest) }) : t(`${C}.line.limits`, { minimum: figure(locale, fewest), maximum: figure(locale, most) })}
                </p>

                {problem === null ? null : (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-surface-sunken px-3 py-2" role="status" data-test="line-problem">
                        <span className="text-copy-13 text-ink">{t(`${C}.problem.${problem}`, { minimum: figure(locale, line.minimum), maximum: most === null ? '' : figure(locale, most) })}</span>
                        {fixTo === null ? (
                            <ActionButton type="button" size="sm" variant="outline" loading={busy} onClick={remove} data-test="fix-remove">
                                {t(`${C}.line.remove`)}
                            </ActionButton>
                        ) : (
                            <ActionButton type="button" size="sm" variant="outline" loading={busy} onClick={() => set(fixTo)} data-test="fix-set">
                                {t(`${C}.problem.set_to`, { quantity: figure(locale, fixTo) })}
                            </ActionButton>
                        )}
                    </div>
                )}
            </div>
        </li>
    );
}

/** The lines that left at a sign-in (R2-8), each named with why; Close puts the notice away on this page. */
function Notice({ cart }: { cart: CartPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const [open, setOpen] = useState(true);

    if (!open) {
        return null;
    }

    return (
        <Alert data-test="cart-notice">
            <AlertTitle className="flex items-start justify-between gap-2">
                <span>{t(`${C}.notice.title`)}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)} data-test="close-notice">
                    {t(`${C}.notice.close`)}
                </Button>
            </AlertTitle>
            <AlertDescription>
                <ul className="grid gap-1">
                    {cart.notice.map((line) => (
                        <li key={`${line.variantId}-${line.mode}`}>
                            <span className="text-ink">{line.name}</span>
                            {line.values === '' ? null : ` · ${line.values}`} · {plural(t, locale, `${C}.pieces`, line.quantity)} — {t(`${C}.notice.${line.reason}`)}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}

/** The coupon kept on the cart for checkout to apply or refuse (R1-15); a guest's box asks them to sign in. */
function Coupon({ code }: { code: string | null }) {
    const t = useTranslator();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(code ?? '');
    const { busy, post } = usePost();

    return (
        <Card className="material-base gap-3 border-0 py-4" data-test="coupon">
            <CardContent className="grid gap-3 px-4">
                {shopper ? (
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            post('storefront.sales.cart.coupon', { code: typed });
                        }}
                    >
                        <TextField id="coupon-code" className="min-w-0 flex-1" label={t(`${C}.coupon.label`)} value={typed} maxLength={64} onChange={(event) => setTyped(event.target.value)} data-test="coupon-code" />
                        <ActionButton type="submit" variant="outline" loading={busy} data-test="apply-coupon">
                            {t(`${C}.coupon.apply`)}
                        </ActionButton>
                    </form>
                ) : (
                    <Link href={link('storefront.sales.cart.sign-in')} className="text-copy-14 text-ink underline underline-offset-4" data-test="coupon-sign-in">
                        {t(`${C}.coupon.sign_in`)}
                    </Link>
                )}
                {code === null ? null : (
                    <p className="flex flex-wrap items-center justify-between gap-2 text-copy-13 text-ink-muted">
                        <span>{t(`${C}.coupon.kept`, { code })}</span>
                        <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => post('storefront.sales.cart.coupon', { code: '' })} data-test="remove-coupon">
                            {t(`${C}.coupon.remove`)}
                        </Button>
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function Summary({ cart, checkout }: { cart: CartPage; checkout: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const reason = cart.problems ? t(`${C}.summary.fix_first`) : shopper && !cart.mayOrder ? t(`${C}.summary.may_not_order`) : undefined;

    return (
        <Card className="material-base gap-3 border-0 py-4" data-test="cart-summary">
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-heading-16 text-ink">{t(`${C}.summary.title`)}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 px-4">
                <dl className="grid gap-1.5 text-copy-14">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">
                            {t(`${C}.summary.subtotal`)} <span className="text-copy-13">({plural(t, locale, `${C}.pieces`, cart.pieces)})</span>
                        </dt>
                        <dd>{cart.subtotal === null ? '—' : <Money value={cart.subtotal} className="text-label-14 text-ink" />}</dd>
                    </div>
                </dl>
                <p className="text-copy-13 text-ink-muted">{t(`${C}.summary.later`)}</p>

                {shopper ? (
                    <ActionButton disabledReason={reason} onClick={() => router.get(link('storefront.sales.checkout'))} data-test="checkout">
                        {t(`${C}.summary.checkout`)}
                    </ActionButton>
                ) : (
                    <>
                        <Button asChild>
                            <Link href={checkout} data-test="checkout">
                                {t(`${C}.summary.checkout`)}
                            </Link>
                        </Button>
                        <p className="text-copy-13 text-ink-muted">{t(`${C}.summary.sign_in_first`)}</p>
                    </>
                )}
                {shopper && !cart.mayOrder ? (
                    <Link href={link('storefront.account')} className="text-copy-13 text-ink underline underline-offset-4" data-test="finish-account">
                        {t(`${C}.summary.finish_account`)}
                    </Link>
                ) : null}
            </CardContent>
        </Card>
    );
}
