import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus, X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { type CardFacts, ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { C, plural, Price, useLocale } from '@/components/sales/shop';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Progress } from '@/components/ui/progress';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { BuyAgainSkippedData, CartLineData, CartPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| S4 - the cart (sales.md §4.1): each line with its photo, name, size, Retail or Wholesale, today's price (the
| old one struck on a sale), − and + within its limits, its total and Remove (R1-11); a line with a problem
| says so with its one fix (R2-10); the lines that left at a sign-in named at the top, with Close (R2-8); the
| coupon kept for checkout, a guest asked to sign in for it (R1-15); the summary with Checkout - out of reach
| while a line is marked (R2-11), and for an account that may not order yet, saying so once (R1-17). A
| guest's Checkout signs in and comes straight back (R1-16). Empty, it says so with Continue Shopping and the
| store's best sellers under it (R2-9, R3). How much more ships free, with a bar (R1-12), the next gift (R1-13)
| and the points to earn (R1-14); the shipping in the summary (R2-12); what the account still needs, with its
| link (R1-17) - each from §4.2 B12, B14.
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
                {cart.skipped.length > 0 ? <Skipped lines={cart.skipped as unknown as BuyAgainSkippedData[]} /> : null}

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
                ) : null}
                {cart.lines.length === 0 && cart.bestSellers.length > 0 ? <BestSellers cards={cart.bestSellers as unknown as ShopCardData[]} facts={cart.cardFacts as CardFacts} /> : null}
                {cart.lines.length === 0 ? null : (
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <ul className="grid min-w-0 content-start gap-3" aria-label={t(`${C}.line.lines`)} data-test="cart-lines">
                            {cart.lines.map((line) => (
                                <Line key={`${line.variantId}-${line.mode}`} line={line} />
                            ))}
                        </ul>
                        <div className="grid min-w-0 content-start gap-4">
                            <Extras cart={cart} />
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

/** The lines that left at a sign-in (R2-8), each named with why; Close puts the notice away for good (B17). */
function Notice({ cart }: { cart: CartPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const [open, setOpen] = useState(true);
    const { post } = usePost();

    if (!open) {
        return null;
    }

    const close = () => {
        setOpen(false);
        post('storefront.sales.cart.notice.dismiss', {});
    };

    return (
        <Alert data-test="cart-notice">
            <AlertTitle className="flex items-start justify-between gap-2">
                <span>{t(`${C}.notice.title`)}</span>
                <Button type="button" variant="ghost" size="sm" onClick={close} data-test="close-notice">
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

/** Buy Again's lines that could not go in, each with why (R2-24). */
function Skipped({ lines }: { lines: BuyAgainSkippedData[] }) {
    const t = useTranslator();
    const locale = useLocale();

    return (
        <Alert data-test="buy-again-skipped">
            <AlertTitle>{t(`${C}.skipped.title`)}</AlertTitle>
            <AlertDescription>
                <ul className="grid gap-1">
                    {lines.map((line, index) => (
                        <li key={`${line.name}-${index}`}>
                            <span className="text-ink">{line.name}</span> · {plural(t, locale, `${C}.pieces`, line.quantity)} — {t(`${C}.skipped.${line.reason}`)}
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
                        <span>
                            <bdi className="text-label-14 text-ink">{code}</bdi> · {t(`${C}.coupon.kept_for`)}
                        </span>
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
    const block = shopper ? cart.mayNotOrder : null;
    const reason = cart.problems ? t(`${C}.summary.fix_first`) : block !== null ? t(`${C}.block.${block}`) : undefined;
    // Each reason's one fix (R1-17): verifying (or changing the phone) on the account page, the company's own page; none for an account closed to orders.
    const fix = block === 'EMAIL_UNVERIFIED' ? link('storefront.account') : block === 'PHONE_UNVERIFIED' || block === 'PHONE_COUNTRY_NOT_SERVED' ? link('storefront.account', { tab: 'phone' }) : block === 'COMPANY_NOT_APPROVED' ? link('storefront.company') : null;

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
                    {/* The fee needs the address: worked out at checkout (gap 6.6). */}
                    <div className="flex items-baseline justify-between gap-3" data-test="summary-shipping">
                        <dt className="text-ink-muted">{t(`${C}.summary.shipping`)}</dt>
                        <dd className="text-copy-13 text-ink-muted">{t(`${C}.summary.shipping_later`)}</dd>
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
                {block === null ? null : (
                    <p className="grid gap-1 text-copy-13 text-ink" role="note" data-test="may-not-order">
                        <span>{t(`${C}.block.${block}`)}</span>
                        {fix === null ? null : (
                            <Link href={fix} className="w-fit underline underline-offset-4" data-test="finish-account">
                                {t(`${C}.block.fix.${block}`)}
                            </Link>
                        )}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/** How much more ships free, with a bar - nothing where the store never ships free (R1-12); the next gift (R1-13); the points to earn (R1-14). */
function Extras({ cart }: { cart: CartPage }) {
    const t = useTranslator();
    const locale = useLocale();
    // The bar only while the store ships everything free from an amount: the fee itself is worked out at checkout (gap 6.6).
    const free = cart.freeAbove !== null;

    if (!free && cart.nextGift === null && cart.pointsToEarn === 0) {
        return null;
    }

    return (
        <Card className="material-base gap-3 border-0 py-4" data-test="cart-extras">
            <CardContent className="grid gap-3 px-4 text-copy-14 text-ink">
                {free ? (
                    <div className="grid gap-2" data-test="free-shipping">
                        <p>
                            {cart.freeShare >= 100 || cart.freeMissing === null ? (
                                t(`${C}.extras.ships_free`)
                            ) : (
                                <>
                                    {t(`${C}.extras.add_more`)} <Money value={cart.freeMissing} className="font-semibold" /> {t(`${C}.extras.for_free_shipping`)}
                                </>
                            )}
                        </p>
                        <Progress value={cart.freeShare} aria-label={t(`${C}.extras.free_bar`)} />
                    </div>
                ) : null}
                {cart.nextGift === null ? null : (
                    <p data-test="next-gift">
                        {t(`${C}.extras.spend_about`)} <Money value={cart.nextGift.missing} className="font-semibold" /> {t(`${C}.extras.for_a_gift`)}
                    </p>
                )}
                {cart.pointsToEarn === 0 ? null : <p data-test="points-to-earn">{plural(t, locale, `${C}.extras.points`, cart.pointsToEarn)}</p>}
            </CardContent>
        </Card>
    );
}

/** The store's best sellers under an empty cart (R2-9, R3): the list pages' cards, the Save heart on each. */
function BestSellers({ cards, facts }: { cards: ShopCardData[]; facts: CardFacts }) {
    const t = useTranslator();
    const all = useCardFacts(facts);

    return (
        <section className="grid gap-4" data-test="best-sellers">
            <h2 className="text-heading-20 text-ink">{t(`${C}.best_sellers`)}</h2>
            <ProductCards cards={cards} facts={all} />
        </section>
    );
}
