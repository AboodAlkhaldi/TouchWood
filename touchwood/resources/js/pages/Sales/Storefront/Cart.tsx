import { Suspense, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { ThemeCartMessage } from '@/components/content/ThemeMarks';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { type CardFacts, ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { ClosedStore, PaymentInTheWay } from '@/components/sales/payment/lazy';
import { C, plural, Price, useLocale } from '@/components/sales/shop';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Progress } from '@/components/ui/progress';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { ShopCardData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { BuyAgainSkippedData, CartLineData, CartPage, CheckoutTryData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
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
|
| To the design (shop-design.md §7): a 34 px heading with the count of products, as the header's; the lines as one
| bordered list (a 96 px photo, the brand, a chip per chosen value and for the way of buying, one stepper box, Remove as
| text) with Continue Shopping under it; one sticky 392 px summary box holding the free-shipping bar, the coupon, a
| 25 px subtotal and a 50 px Checkout. On a phone: a grey page, the compact top bar, and the summary's Checkout pinned to
| the bottom with the subtotal before VAT - the same button, so it is one - the tab bar hidden.
*/

export default function Cart(cart: CartPage) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const { shop } = usePage<SharedProps>().props;
    const home = shop ? `/${shop.code}/${locale}` : '/';
    const [closed, setClosed] = useState<CheckoutTryData | null>(null);

    if (closed !== null) {
        return (
            <Suspense fallback={null}>
                <ClosedStore view={closed} />
            </Suspense>
        );
    }

    return (
        <StorefrontLayout title={t(`${C}.title`)} compact={{ back: home }} tabBar={cart.lines.length === 0}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 max-sm:-mx-4 max-sm:-my-6 max-sm:bg-surface-sunken max-sm:px-4 max-sm:py-5">
                <h1 className="flex flex-wrap items-baseline gap-x-3 text-[26px] leading-tight font-semibold text-ink sm:text-[34px]" data-test="cart-heading">
                    {t(`${C}.title`)}
                    {cart.lines.length > 0 ? <span className="tw-figure text-copy-16 font-normal text-ink-muted">{plural(t, locale, `${C}.products`, cart.lines.length)}</span> : null}
                </h1>
                {/* A live theme's cart message (content.md §1.8 part 9), from the frame. */}
                <ThemeCartMessage />
                <FormError />
                {cart.payment === null ? null : (
                    <Suspense fallback={null}>
                        <PaymentInTheWay payment={cart.payment} onClosed={setClosed} />
                    </Suspense>
                )}
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
                    <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,1fr)_392px] lg:gap-8">
                        <div className="grid min-w-0 content-start gap-4">
                            <ul className="grid min-w-0 content-start divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface" aria-label={t(`${C}.line.lines`)} data-test="cart-lines">
                                {cart.lines.map((line) => (
                                    <Line key={`${line.variantId}-${line.mode}`} line={line} />
                                ))}
                            </ul>
                            <Link href={home} className="w-fit text-label-14 text-brand underline-offset-4 hover:underline" data-test="continue-shopping">
                                {t(`${C}.continue`)}
                            </Link>
                        </div>
                        <Summary cart={cart} checkout={link('storefront.sales.cart.sign-in')} />
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
    const chosen = (line.chosen as string[] | undefined) ?? [];

    return (
        <li className="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-3 p-3 sm:grid-cols-[6rem_minmax(0,1fr)] sm:gap-4 sm:p-4" data-test={`cart-line-${line.code}`}>
            <ShopPhoto photo={line.photo as PhotoSizes | null} size="thumb" alt="" className="aspect-square w-full rounded-lg bg-surface-sunken object-contain" />
            <div className="grid min-w-0 gap-2">
                <div className="flex min-w-0 items-start justify-between gap-3">
                    <div className="grid min-w-0 gap-1">
                        {line.brand ? <span className="text-label-12 text-ink-muted" data-test="line-brand">{line.brand}</span> : null}
                        {line.slug === null ? (
                            <span className="text-label-14 text-ink">{line.name}</span>
                        ) : (
                            <Link href={link('storefront.catalog.product', { slug: line.slug })} className="text-label-14 text-ink underline-offset-2 hover:underline">
                                {line.name}
                            </Link>
                        )}
                        {/* A chip for each chosen value and for the way of buying (§7). */}
                        <span className="flex flex-wrap items-center gap-1.5" data-test="line-chips">
                            {chosen.map((value) => (
                                <span key={value} className="rounded-full bg-surface-sunken px-2.5 py-0.5 text-label-12 text-ink">
                                    {value}
                                </span>
                            ))}
                            <span className={`rounded-full px-2.5 py-0.5 text-label-12 ${line.mode === 'WHOLESALE' ? 'bg-accent/10 text-accent' : 'bg-brand/10 text-brand'}`} data-test="line-mode">
                                {t(`${C}.mode.${line.mode}`)}
                            </span>
                        </span>
                    </div>
                    <div className="grid justify-items-end gap-0.5 text-end">
                        {line.total === null ? <span className="text-copy-14 text-ink-muted">{t(`${C}.line.no_price`)}</span> : <Money value={line.total} className="text-label-16 font-semibold text-ink" />}
                        {line.unit === null ? null : (
                            <span className="text-copy-13 text-ink-muted">
                                <Price now={line.unit} before={line.before} /> · {t(`catalog::shop.unit.${line.saleUnit}`)}
                            </span>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    {/* One stepper box (§7). */}
                    <div className="flex h-10 items-center rounded-md border border-line" role="group" aria-label={t(`${C}.line.quantity`, { name: line.name })}>
                        <Button type="button" variant="ghost" size="icon" className="h-full w-9 rounded-none" disabled={busy || line.quantity <= fewest} onClick={() => set(line.quantity - 1)} aria-label={t(`${C}.line.less`)} data-test="less">
                            <Minus aria-hidden="true" />
                        </Button>
                        <output className="tw-figure grid h-full min-w-11 place-items-center border-x border-line text-label-14 text-ink" aria-live="polite" data-test="quantity">
                            {figure(locale, line.quantity)}
                        </output>
                        <Button type="button" variant="ghost" size="icon" className="h-full w-9 rounded-none" disabled={busy || (most !== null && line.quantity >= most)} onClick={() => set(line.quantity + 1)} aria-label={t(`${C}.line.more`)} data-test="more">
                            <Plus aria-hidden="true" />
                        </Button>
                    </div>
                    <Button type="button" variant="link" className="h-auto p-0 text-copy-14 text-ink-muted hover:text-bad" disabled={busy} onClick={remove} aria-label={`${t(`${C}.line.remove`)}: ${line.name}`} data-test="remove-line">
                        {t(`${C}.line.remove`)}
                    </Button>
                </div>

                <p className="text-copy-13 text-ink-muted">
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

/** The coupon kept on the cart for checkout to apply or refuse (R1-15); a guest's box asks them to sign in. Inside the summary (§7). */
function Coupon({ code }: { code: string | null }) {
    const t = useTranslator();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const [typed, setTyped] = useState(code ?? '');
    const { busy, post } = usePost();

    return (
        <div className="grid gap-3 border-t border-line pt-4" data-test="coupon">
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
        </div>
    );
}

/**
 * One sticky box (§7): the free-shipping bar, the next gift and the points (R1-12 to R1-14), the subtotal at 25 px, the
 * shipping worked out at checkout, the coupon, and a 50 px Checkout. On a phone the Checkout row is pinned to the bottom
 * of the screen with the subtotal before VAT - the same button, so a phone and a computer press one Checkout.
 */
function Summary({ cart, checkout }: { cart: CartPage; checkout: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const block = shopper ? cart.mayNotOrder : null;
    const reason = cart.problems ? t(`${C}.summary.fix_first`) : block !== null ? t(`${C}.block.${block}`) : undefined;
    // Each reason's one fix (R1-17): verifying (or changing the phone) on the account page, the company's own page; none for an account closed to orders.
    const fix = block === 'EMAIL_UNVERIFIED' ? link('storefront.account') : block === 'PHONE_UNVERIFIED' || block === 'PHONE_COUNTRY_NOT_SERVED' ? link('storefront.account', { tab: 'phone' }) : block === 'COMPANY_NOT_APPROVED' ? link('storefront.company') : null;
    // The bar only while the store ships everything free from an amount: the fee itself is worked out at checkout (gap 6.6).
    const free = cart.freeAbove !== null;

    return (
        <section className="grid min-w-0 content-start gap-4 self-start rounded-xl border border-line bg-surface p-4 sm:p-5 lg:sticky lg:top-36" aria-labelledby="cart-summary-title" data-test="cart-summary">
            <h2 id="cart-summary-title" className="text-heading-16 text-ink">
                {t(`${C}.summary.title`)}
            </h2>

            {free || cart.nextGift !== null || cart.pointsToEarn > 0 ? (
                <div className="grid gap-3 text-copy-14 text-ink" data-test="cart-extras">
                    {free ? (
                        <div className="grid gap-2 rounded-lg bg-surface-sunken p-3" data-test="free-shipping">
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
                </div>
            ) : null}

            <dl className="grid gap-2 text-copy-14">
                <div className="flex items-baseline justify-between gap-3">
                    <dt className="text-ink-muted">
                        {t(`${C}.summary.subtotal`)} <span className="text-copy-13">({plural(t, locale, `${C}.pieces`, cart.pieces)})</span>
                    </dt>
                    <dd>{cart.subtotal === null ? '—' : <Money value={cart.subtotal} className="text-[22px] font-semibold text-ink sm:text-[25px]" />}</dd>
                </div>
                {/* The fee needs the address: worked out at checkout (gap 6.6). */}
                <div className="flex items-baseline justify-between gap-3" data-test="summary-shipping">
                    <dt className="text-ink-muted">{t(`${C}.summary.shipping`)}</dt>
                    <dd className="text-copy-13 text-ink-muted">{t(`${C}.summary.shipping_later`)}</dd>
                </div>
            </dl>
            <p className="text-copy-13 text-ink-muted">{t(`${C}.summary.later`)}</p>

            {/* A computer's Checkout; on a phone the same row is pinned to the bottom with the subtotal before VAT. */}
            <div className={`grid gap-2 max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:z-40 ${cart.subtotal === null ? '' : 'max-sm:grid-cols-[auto_minmax(0,1fr)]'} max-sm:items-center max-sm:gap-3 max-sm:border-t max-sm:border-line max-sm:bg-surface max-sm:px-4 max-sm:pt-2 max-sm:pb-[calc(0.5rem+env(safe-area-inset-bottom))]`} data-test="checkout-row">
                {cart.subtotal === null ? null : (
                    <span className="grid leading-tight sm:hidden" data-test="checkout-bar-subtotal">
                        <Money value={cart.subtotal} className="text-label-16 font-semibold text-ink" />
                        <span className="text-label-12 text-ink-muted">{t(`${C}.summary.before_vat`)}</span>
                    </span>
                )}
                {shopper ? (
                    <ActionButton className="h-12 w-full text-label-16 sm:h-[50px]" disabledReason={reason} onClick={() => router.get(link('storefront.sales.checkout'))} data-test="checkout">
                        {t(`${C}.summary.checkout`)}
                    </ActionButton>
                ) : (
                    <Button asChild className="h-12 w-full text-label-16 sm:h-[50px]">
                        <Link href={checkout} data-test="checkout">
                            {t(`${C}.summary.checkout`)}
                        </Link>
                    </Button>
                )}
            </div>
            {shopper ? null : <p className="text-copy-13 text-ink-muted">{t(`${C}.summary.sign_in_first`)}</p>}
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

            <Coupon code={cart.couponCode} />
        </section>
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
