import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Money } from '@/components/Money';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { plural, useLocale } from '@/components/sales/shop';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { isolate } from '@/lib/bidi';
import { figure, toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CheckoutCarrierData, CheckoutPage } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';

/*
| S5 - checkout (sales.md §4.1), one page (R1-18) whose quote is worked out again each time something on it
| changes: the delivery address (the default first, R1-19); the coupon as the cart left it, applied or refused
| with its reason, and "Use my points" with a box to use fewer (R1-20); the way to pay - an individual is told
| staff will be in touch (R2-13), a company chooses, nothing chosen first (R2-14), a bank transfer with the
| store's details to copy (R1-24); the summary with the lines that hold something (R2-15), the gift at 0.00,
| the points earned and, once, that prices exclude VAT (R2-1). Place Order sends the quote; pressed twice it
| places once (§1.3). A changed quote comes back here with its new totals and why (R1-21, R1-22). Delivery (§4.3):
| the carriers serving the address, the cheapest first; none serving it, a fee staff confirm; another store's
| country, a way there and no quote.
*/

const W = 'sales::shop_checkout';

type Choice = { address?: string | null; points?: boolean; asked?: number | null; way?: string | null; carrier?: string | null };

export default function Checkout(page: CheckoutPage) {
    const t = useTranslator();
    const link = useLink();
    const [busy, setBusy] = useState(false);
    // Each choice worked out again by the server, kept in the address so a reload keeps it.
    const choose = (next: Choice) => {
        // The carrier the customer asked for, not the cheapest picked for them: it stays while it serves the address (E4).
        const carrier = page.carrierChanged ? null : new URLSearchParams(window.location.search).get('carrier');
        const now = { address: page.addressId, points: page.usePoints, asked: page.pointsAsked, way: page.way, carrier, ...next };
        const query: Record<string, string> = {};

        if (now.address) query.address = now.address;
        if (now.points) query.points = '1';
        if (now.points && now.asked !== null && now.asked !== undefined) query.asked = String(now.asked);
        if (now.way) query.way = now.way;
        if (now.carrier) query.carrier = now.carrier;

        router.get(link('storefront.sales.checkout'), query, { preserveScroll: true, replace: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <StorefrontLayout title={t(`${W}.title`)}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6">
                <h1 className="text-heading-24 text-ink">{t(`${W}.title`)}</h1>
                <FormError />
                <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="grid min-w-0 content-start gap-4">
                        <Addresses page={page} busy={busy} choose={choose} />
                        {page.quoteId === null ? null : (
                            <>
                                <Delivery page={page} busy={busy} choose={choose} />
                                <CouponAndPoints page={page} busy={busy} choose={choose} />
                                <WayToPay page={page} busy={busy} choose={choose} />
                            </>
                        )}
                    </div>
                    {page.quoteId === null ? null : <Summary page={page} busy={busy} />}
                </div>
            </div>
        </StorefrontLayout>
    );
}

type Part = { page: CheckoutPage; busy: boolean; choose: (next: Choice) => void };

function Section({ title, test, children }: { title: string; test: string; children: ReactNode }) {
    return (
        <Card className="material-base gap-3 border-0 py-4" data-test={test}>
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-heading-16 text-ink">{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 px-4">{children}</CardContent>
        </Card>
    );
}

function Addresses({ page, busy, choose }: Part) {
    const t = useTranslator();
    const link = useLink();
    const manage = link('storefront.account', { tab: 'addresses' });

    return (
        <Section title={t(`${W}.address.title`)} test="checkout-address">
            {page.addresses.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t(`${W}.address.none`)}</p>
            ) : (
                <RadioGroup value={page.addressId ?? ''} onValueChange={(address) => choose({ address })} disabled={busy} className="grid gap-2" aria-label={t(`${W}.address.title`)}>
                    {page.addresses.map((address) => (
                        <Label key={address.id} htmlFor={`address-${address.id}`} className="material-small flex cursor-pointer items-start gap-3 p-3 font-normal has-[[data-disabled]]:cursor-not-allowed" data-test={`address-${address.id}`}>
                            <RadioGroupItem id={`address-${address.id}`} value={address.id} disabled={!address.isComplete} className="mt-0.5" />
                            <span className="grid min-w-0 gap-0.5">
                                <span className="flex flex-wrap items-center gap-2 text-label-14 text-ink">
                                    <bdi>{address.label}</bdi>
                                    {address.isDefault ? <Badge variant="outline">{t(`${W}.address.default`)}</Badge> : null}
                                </span>
                                <span className="text-copy-13 text-ink-muted">
                                    <bdi>{address.recipientName}</bdi>
                                </span>
                                <span className="text-copy-13 whitespace-pre-line text-ink-muted">{address.formatted}</span>
                                {address.isComplete ? null : <span className="text-copy-13 text-ink">{t(`${W}.address.incomplete`)}</span>}
                            </span>
                        </Label>
                    ))}
                </RadioGroup>
            )}
            {/* The courier calls the account's verified phone, whichever address is chosen (access.md amendment 89). */}
            {page.phone === null ? null : (
                <p className="text-copy-13 text-ink-muted" data-test="checkout-phone">
                    {t(`${W}.address.account_phone`)}:{' '}
                    <bdi dir="ltr" className="tw-figure">
                        {page.phone}
                    </bdi>
                </p>
            )}
            {page.otherStore === null ? null : (
                <div className="grid gap-1 rounded-md bg-surface-sunken p-3 text-copy-14" data-test="other-store">
                    <p className="text-ink">{t(`${W}.delivery.other_store`, { store: isolate(page.otherStore.name) })}</p>
                    <a href={page.otherStore.url} className="w-fit text-ink underline underline-offset-4" data-test="other-store-link">
                        {t(`${W}.delivery.other_link`, { store: isolate(page.otherStore.name) })}
                    </a>
                    <p className="text-copy-13 text-ink-muted">{t(`${W}.delivery.other_cart`)}</p>
                </div>
            )}
            <Link href={manage} className="text-copy-14 text-ink underline underline-offset-4" data-test="manage-addresses">
                {page.addresses.length === 0 ? t(`${W}.address.add`) : t(`${W}.address.manage`)}
            </Link>
        </Section>
    );
}

/** A carrier's logo if it has one, its name, and its fee for this order: Free when free shipping made it 0 (E3). */
function CarrierLine({ carrier }: { carrier: CheckoutCarrierData }) {
    const t = useTranslator();

    return (
        <span className="flex min-w-0 flex-1 items-center justify-between gap-3">
            <span className="flex min-w-0 items-center gap-2">
                {carrier.logoUrl === null ? null : <img src={carrier.logoUrl} alt="" loading="lazy" className="h-6 w-auto max-w-16 shrink-0 object-contain" />}
                <bdi className="min-w-0 text-copy-14 break-words text-ink">{carrier.name}</bdi>
            </span>
            {carrier.free || carrier.fee.minor === 0 ? <span className="shrink-0 text-label-14 text-ink">{t(`${W}.delivery.free`)}</span> : <Money value={carrier.fee} className="shrink-0 text-label-14 text-ink" />}
        </span>
    );
}

/** The carriers serving the address (§4.3): radios for two or more (the cheapest picked first), one with nothing to choose (E2), none: a fee staff confirm. */
function Delivery({ page, busy, choose }: Part) {
    const t = useTranslator();
    const [only] = page.carriers;

    if (!page.shippingPending && only === undefined) {
        return null;
    }

    return (
        <Section title={t(`${W}.delivery.title`)} test="checkout-delivery">
            {page.carrierChanged ? (
                <p role="status" className="text-copy-13 text-ink" data-test="carrier-changed">
                    {t(`${W}.delivery.changed`)}
                </p>
            ) : null}
            {page.shippingPending ? (
                <p className="text-copy-14 text-ink" data-test="delivery-pending">
                    {t(`${W}.delivery.none`)}
                </p>
            ) : page.carriers.length === 1 && only !== undefined ? (
                <div className="material-small flex items-center p-3" data-test={`carrier-${only.id}`}>
                    <CarrierLine carrier={only} />
                </div>
            ) : (
                <RadioGroup value={page.carrierId ?? ''} onValueChange={(carrier) => choose({ carrier })} disabled={busy} className="grid gap-2" aria-label={t(`${W}.delivery.title`)}>
                    {page.carriers.map((carrier) => (
                        <Label key={carrier.id} htmlFor={`carrier-${carrier.id}`} className="material-small flex cursor-pointer items-center gap-3 p-3 font-normal" data-test={`carrier-${carrier.id}`}>
                            <RadioGroupItem id={`carrier-${carrier.id}`} value={carrier.id} />
                            <CarrierLine carrier={carrier} />
                        </Label>
                    ))}
                </RadioGroup>
            )}
        </Section>
    );
}

function CouponAndPoints({ page, busy, choose }: Part) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const [asked, setAsked] = useState(page.pointsAsked === null ? '' : String(page.pointsAsked));

    return (
        <Section title={`${t(`${W}.coupon.title`)} · ${t(`${W}.points.title`)}`} test="checkout-discounts">
            <div className="grid gap-1 text-copy-14" data-test="checkout-coupon">
                {page.couponCode === null ? (
                    <p className="text-ink-muted">{t(`${W}.coupon.none`)}</p>
                ) : page.couponRefusal === null ? (
                    <p className="text-ink">
                        <bdi className="text-label-14">{page.couponCode}</bdi> · {t(`${W}.coupon.applied_short`)}
                        {page.couponName === null ? null : <span className="text-ink-muted"> · {page.couponName}</span>}
                    </p>
                ) : (
                    <p className="text-ink">
                        <bdi className="text-label-14">{page.couponCode}</bdi> · {t(`${W}.coupon.refused_why`)} {t(`${W}.coupon.refusal.${page.couponRefusal}`)}
                    </p>
                )}
                {page.couponCode === null ? null : (
                    <Link href={link('storefront.sales.cart')} className="w-fit text-copy-13 text-ink-muted underline underline-offset-4">
                        {t(`${W}.coupon.change`)}
                    </Link>
                )}
            </div>

            <div className="grid gap-2 border-t border-line pt-3" data-test="checkout-points">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox checked={page.usePoints} disabled={busy} onCheckedChange={(on) => choose({ points: on === true, asked: null })} data-test="use-points" />
                    <span className="text-copy-14 text-ink">{t(`${W}.points.use`)}</span>
                </Label>
                {page.usePoints && page.pointsRefusal !== null ? (
                    <p className="text-copy-13 text-ink">
                        {t(`${W}.points.refused`)} {t(`${W}.points.refusal.${page.pointsRefusal}`)}
                    </p>
                ) : null}
                {page.usePoints && page.pointsUsed > 0 ? <p className="text-copy-13 text-ink-muted">{t(`${W}.points.used`, { points: figure(locale, page.pointsUsed) })}</p> : null}
                {page.usePoints && page.pointsMode === 'PARTIAL' && page.pointsMostUsable > 0 ? (
                    <form
                        className="flex items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const typed = asked.trim();
                            // None, or 0: as many as allowed.
                            choose({ asked: typed === '' || Number(typed) === 0 ? null : Number(typed) });
                        }}
                    >
                        <TextField
                            id="points-asked"
                            className="min-w-0 flex-1"
                            inputMode="numeric"
                            dir="ltr"
                            inputClassName="tw-figure"
                            label={t(`${W}.points.fewer`)}
                            helper={t(`${W}.points.fewer_helper`, { most: figure(locale, page.pointsMostUsable) })}
                            value={asked}
                            onChange={(event) => setAsked(toLatinDigits(event.target.value).replace(/[^0-9]/g, ''))}
                            data-test="points-asked"
                        />
                        <ActionButton type="submit" variant="outline" loading={busy} data-test="apply-points">
                            {t(`${W}.points.apply`)}
                        </ActionButton>
                    </form>
                ) : null}
            </div>
        </Section>
    );
}

function WayToPay({ page, busy, choose }: Part) {
    const t = useTranslator();

    return (
        <Section title={t(`${W}.way.title`)} test="checkout-way">
            {page.ways.length <= 1 ? (
                <p className="text-copy-14 text-ink">{t(`${W}.way.contact`)}</p>
            ) : (
                <RadioGroup value={page.way ?? ''} onValueChange={(way) => choose({ way })} disabled={busy} className="grid gap-2" aria-label={t(`${W}.way.choose`)}>
                    {page.ways.map((way) => (
                        <Label key={way} htmlFor={`way-${way}`} className="material-small flex cursor-pointer items-center gap-3 p-3 font-normal" data-test={`way-${way}`}>
                            <RadioGroupItem id={`way-${way}`} value={way} />
                            <span className="text-copy-14 text-ink">{t(`${W}.way.${way}`)}</span>
                        </Label>
                    ))}
                </RadioGroup>
            )}
            {page.way === 'BANK_TRANSFER' && page.bank !== null ? <Bank bank={page.bank} /> : null}
        </Section>
    );
}

export function Bank({ bank }: { bank: NonNullable<CheckoutPage['bank']> }) {
    const t = useTranslator();
    const rows: [string, string, boolean][] = [
        [t(`${W}.way.iban`), bank.iban, true],
        [t(`${W}.way.bank_name`), bank.bank, false],
        [t(`${W}.way.holder`), bank.holder, false],
    ];

    return (
        <div className="grid gap-2 rounded-md bg-surface-sunken p-3" data-test="bank-details">
            <p className="text-label-13 text-ink">{t(`${W}.way.bank`)}</p>
            <dl className="grid gap-1.5 text-copy-14">
                {rows.map(([label, value, latin]) => (
                    <div key={label} className="flex min-w-0 items-center justify-between gap-2">
                        <dt className="shrink-0 text-ink-muted">{label}</dt>
                        <dd className="flex min-w-0 items-center gap-1">
                            <bdi dir={latin ? 'ltr' : undefined} className={latin ? 'tw-figure break-all' : 'break-words'}>
                                {value}
                            </bdi>
                            <CopyButton text={value} label={t(`${W}.way.copy`, { what: label })} />
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

function Summary({ page, busy }: { page: CheckoutPage; busy: boolean }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const [placing, setPlacing] = useState(false);
    // [label, amount, taken off]: a line with nothing in it is not shown (R2-15); shipping and VAT always are.
    const free = page.shipping !== null && page.shipping.minor === 0 ? t(`${W}.summary.free`) : page.shipping;
    const rows: [string, MoneyData | string | null, boolean][] = [
        [t(`${W}.summary.subtotal`), page.subtotal, false],
        [t(`${W}.summary.savings`), page.savings, true],
        [t(`${W}.summary.coupon`), page.coupon, true],
        [t(`${W}.summary.points`), page.points, true],
        // No carrier serves the address: the fee is to be set by staff (§9.5 #2).
        [t(`${W}.summary.shipping`), page.shippingPending ? t(`${W}.summary.shipping_pending`) : free, false],
        [t(`${W}.summary.vat`, { rate: figure(locale, page.vatRateBasisPoints / 100) }), page.vat, false],
    ];
    const reason = page.addressId === null ? t(`${W}.summary.choose_address`) : page.way === null ? t(`${W}.summary.choose_way`) : undefined;

    return (
        <Card className="material-base h-fit gap-3 border-0 py-4 lg:sticky lg:top-4" data-test="checkout-summary">
            <CardHeader className="px-4">
                <CardTitle>
                    <h2 className="text-heading-16 text-ink">{t(`${W}.summary.title`)}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 px-4">
                <dl className="grid gap-1.5 text-copy-14">
                    {rows
                        .filter(([, value]) => value !== null)
                        .map(([label, value, minus]) => (
                            <div key={label} className="flex items-baseline justify-between gap-3">
                                <dt className="text-ink-muted">{label}</dt>
                                <dd className="text-ink">
                                    {minus ? <span aria-hidden="true">− </span> : null}
                                    {typeof value === 'string' ? value : <Money value={value as MoneyData} />}
                                </dd>
                            </div>
                        ))}
                    {page.gift === null || page.giftPrice === null ? null : (
                        <div className="flex items-baseline justify-between gap-3" data-test="summary-gift">
                            <dt className="text-ink-muted">{t(`${W}.summary.gift`, { name: page.gift })}</dt>
                            <dd className="text-ink">
                                <Money value={page.giftPrice} />
                            </dd>
                        </div>
                    )}
                    {page.total === null ? null : (
                        <div className="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                            <dt className="text-label-14 text-ink" data-test="summary-total-label">
                                {page.shippingPending ? t(`${W}.summary.total_before`) : t(`${W}.summary.total`)}
                            </dt>
                            <dd>
                                <Money value={page.total} className="text-heading-16 text-ink" />
                            </dd>
                        </div>
                    )}
                </dl>
                {page.earns > 0 ? <p className="text-copy-13 text-ink-muted">{plural(t, locale, `${W}.summary.earns`, page.earns)}</p> : null}
                <p className="text-copy-13 text-ink-muted">{t(`${W}.summary.vat_note`)}</p>
                <ActionButton
                    size="lg"
                    loading={placing || busy}
                    disabledReason={reason}
                    onClick={() =>
                        // The quote's id only, never an amount (handoff §2); the server places it once however often it is sent.
                        router.post(link('storefront.sales.place'), { quote: page.quoteId } as never, { preserveScroll: true, onStart: () => setPlacing(true), onFinish: () => setPlacing(false) })
                    }
                    data-test="place-order"
                >
                    {/* The total isn't final while the fee is to be set (E6). */}
                    {page.shippingPending ? t(`${W}.summary.place_pending`) : t(`${W}.summary.place`, { total: page.total?.text ?? '' })}
                </ActionButton>
            </CardContent>
        </Card>
    );
}
