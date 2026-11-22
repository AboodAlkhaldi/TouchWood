import { type ReactNode, useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { toast } from 'sonner';
import { ActionButton } from '@/components/ActionButton';
import { Money } from '@/components/Money';
import { plural, useLocale } from '@/components/sales/shop';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/cn';
import { figure, toLatinDigits } from '@/lib/digits';
import { moneyTimes } from '@/lib/money';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { ShopSellingData, ShopVariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { ShopWholesaleSizeData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { ShopCartData, ShopCartSizeData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| S2 - Add to Cart on a product's page (sales.md §4.1; R1-9), to the design (shop-design.md §6.3, §6.4): Retail or
| Wholesale for a viewer the page sells both to - the switch drawn in the page's price box (`ModeSwitch`), each with its
| own limits (R1-7); one 48 px row of a − and + stepper whose number can be typed (C13), Add to Cart and the page's heart;
| the total for the pieces typed, before VAT. The quantity starts at the minimum and stays within the limits (R1-8).
| Pressed before a size is chosen, it points at the choices and adds nothing (R2-6). Added, the shop's toast says so with
| View Cart for 6 seconds (R2-7). A size out of stock, or not for this account, the cart's command refuses in its own
| words (R1-2). Drawn only where Sales offers it: never on a "Not available now" page (catalog.md §1.4). Under the
| Wholesale choice, the size's wholesale prices - from its first piece, then from each band's start - the one the
| quantity typed buys at highlighted (S1; R2-3). On a phone the buy row is fixed at the bottom (§6.6).
|
| Once a size is chosen, what this viewer may add of it is Sales's own (§4.2 B15): the modes it sells to them now,
| each mode's limits counted with the pieces its line already holds - an add joins that line - and Out of
| stock when too few are free for the least add; the pieces of it already in the cart said under the box.
*/

export type Mode = 'RETAIL' | 'WHOLESALE';

/** Its own words: a product's page carries these, not the cart's. */
const C = 'sales::shop_add';

/** The chosen size as Sales offers it; none before a size is chosen, when the page's terms stand in. */
function sizeOf(variant: ShopVariantData | null, cart: ShopCartData | null): ShopCartSizeData | null {
    return variant === null || cart === null ? null : (cart.sizes.find((each) => each.variantId === variant.id) ?? null);
}

/** The modes this viewer may buy the chosen size in, and the one chosen: the page's price box draws the switch. */
export function useSaleModes(variant: ShopVariantData | null, selling: ShopSellingData | null, cart: ShopCartData | null): { modes: Mode[]; mode: Mode; setMode: (mode: Mode) => void } {
    const size = sizeOf(variant, cart);
    const known = variant === null || cart === null || size !== null;
    const wholesale = selling === null || !known ? false : size !== null ? size.wholesale !== null : variant === null ? selling.wholesaleMinimum !== null : variant.sellsWholesale && selling.wholesaleMinimum !== null;
    const retail = selling === null || !known ? false : size !== null ? size.retail !== null : variant === null ? true : variant.sellsRetail;
    const modes: Mode[] = [...(retail ? (['RETAIL'] as const) : []), ...(wholesale ? (['WHOLESALE'] as const) : [])];
    const [mode, setMode] = useState<Mode>(modes[0] ?? 'RETAIL');

    // Another size that does not sell in this mode: its first mode.
    useEffect(() => {
        if (!modes.includes(mode) && modes[0]) {
            setMode(modes[0]);
        }
    }, [modes, mode]);

    return { modes, mode: modes.includes(mode) ? mode : (modes[0] ?? 'RETAIL'), setMode };
}

/** Retail or Wholesale, in the price box; nothing where only one is sold. */
export function ModeSwitch({ modes, mode, onChange }: { modes: Mode[]; mode: Mode; onChange: (mode: Mode) => void }) {
    const t = useTranslator();

    return modes.length > 1 ? (
        <ToggleGroup
            type="single"
            value={mode}
            onValueChange={(next) => (next === 'RETAIL' || next === 'WHOLESALE' ? onChange(next) : undefined)}
            aria-label={t(`${C}.add.mode`)}
            className={`${SWITCH_TRACK} w-full`}
            data-test="sale-mode"
        >
            {modes.map((each) => (
                <ToggleGroupItem key={each} value={each} className={`${SWITCH_ITEM} flex-1`} data-test={`mode-${each}`}>
                    {t(`${C}.mode.${each}`)}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    ) : null;
}

export function AddToCart({
    variant,
    selling,
    choicesId,
    mode,
    modes,
    wholesalePrices = null,
    cart = null,
    heart = null,
}: {
    variant: ShopVariantData | null;
    selling: ShopSellingData;
    choicesId: string;
    /** The mode the price box's switch chose (`useSaleModes`). */
    mode: Mode;
    modes: Mode[];
    /** Pricing's prices for the chosen size, where it sells wholesale to this viewer. */
    wholesalePrices?: ShopWholesaleSizeData | null;
    /** Sales's section: what this viewer may add of each size. */
    cart?: ShopCartData | null;
    /** The page's heart, at the row's end. */
    heart?: ReactNode;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const size = sizeOf(variant, cart);
    const line = size === null ? null : mode === 'WHOLESALE' ? size.wholesale : size.retail;
    // An add joins the mode's line: the line's limits cover what it holds and the add together (B15).
    const minimum = line !== null ? Math.max(1, line.minimum - line.inCart) : Math.max(1, mode === 'WHOLESALE' ? (selling.wholesaleMinimum ?? 1) : selling.retailMinimum);
    const maximum = line !== null ? (line.maximum === null ? null : line.maximum - line.inCart) : mode === 'WHOLESALE' ? selling.wholesaleMaximum : selling.retailMaximum;
    const full = maximum !== null && maximum < minimum;
    const outOfStock = size !== null && (size.outOfStock || (line?.outOfStock ?? false));
    const [typed, setTyped] = useState(String(minimum));
    const [busy, setBusy] = useState(false);
    const [pointed, setPointed] = useState(false);

    // Another mode or size: back to its first piece count.
    useEffect(() => setTyped(String(minimum)), [minimum]);

    if (modes.length === 0) {
        return variant === null ? null : (
            <p className="text-copy-14 text-ink" role="status" data-test="not-sold">
                {t(`${C}.add.not_sold`)}
            </p>
        );
    }

    const quantity = Number(typed);
    const outside = !Number.isInteger(quantity) || quantity < minimum || (maximum !== null && quantity > maximum);
    const limits = maximum === null ? t(`${C}.add.from`, { minimum: figure(locale, minimum) }) : t(`${C}.add.limits`, { minimum: figure(locale, minimum), maximum: figure(locale, Math.max(minimum, maximum)) });
    // Why nothing can be added of this size now: too few free, or its line already holds the most.
    const blocked = full ? t(`${C}.add.full`) : outOfStock ? t(`${C}.add.out_of_stock`) : undefined;
    // What a piece costs at this quantity: the retail price, or the wholesale band the line's pieces reach.
    const unit: MoneyData | null = mode === 'WHOLESALE' ? (wholesalePrices === null ? null : unitAt(wholesalePrices, line?.minimum ?? minimum, quantity + (line?.inCart ?? 0))) : (variant?.price ?? null);
    const step = (by: number) => setTyped(String(Math.min(maximum ?? Number.MAX_SAFE_INTEGER, Math.max(minimum, (Number.isInteger(quantity) ? quantity : minimum) + by))));

    const add = () => {
        if (variant === null) {
            // Nothing added: the choices are where to look (R2-6).
            setPointed(true);
            document.getElementById(choicesId)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.getElementById(choicesId)?.querySelector<HTMLElement>('button:not(:disabled)')?.focus({ preventScroll: true });

            return;
        }

        router.post(link('storefront.sales.cart.add'), { variant: variant.id, mode, quantity } as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: (page) => {
                const errors = (page.props as { errors?: Record<string, string> }).errors ?? {};

                // The shop's toast, small and dark at the bottom, for 6 seconds (shop-design.md §6.4): S2's words kept.
                if (Object.keys(errors).length === 0) {
                    toast(
                        <span className="flex flex-wrap items-center gap-2" data-test="added">
                            {t(`${C}.add.added`, { pieces: plural(t, locale, `${C}.pieces`, quantity) })}
                            <Link href={link('storefront.sales.cart')} className="font-semibold underline underline-offset-4" data-test="view-cart">
                                {t(`${C}.add.view`)}
                            </Link>
                        </span>,
                        { duration: 6000 },
                    );
                }
            },
        });
    };

    return (
        <section className="grid gap-3" data-test="add-to-cart">
            {mode === 'WHOLESALE' && wholesalePrices !== null ? <Bands wholesale={wholesalePrices} first={line?.minimum ?? minimum} quantity={outside ? null : quantity + (line?.inCart ?? 0)} /> : null}

            {/* One 48 px row; fixed at the bottom of a phone's screen (§6.6). */}
            <div className="flex items-center gap-2 max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:z-40 max-sm:border-t max-sm:border-line max-sm:bg-surface max-sm:px-4 max-sm:pt-2 max-sm:pb-[calc(0.5rem+env(safe-area-inset-bottom))]" data-test="buy-row">
                <div className="flex h-12 shrink-0 items-center rounded-md border border-line" role="group" aria-label={t(`${C}.add.quantity`)}>
                    <Button type="button" variant="ghost" size="icon" className="h-full w-10 rounded-none" aria-label={t(`${C}.add.less`)} disabled={!Number.isInteger(quantity) || quantity <= minimum} onClick={() => step(-1)} data-test="add-less">
                        <Minus aria-hidden="true" />
                    </Button>
                    <input
                        id="add-quantity"
                        inputMode="numeric"
                        dir="ltr"
                        aria-label={t(`${C}.add.quantity`)}
                        aria-describedby="add-limits"
                        className="tw-figure h-full w-14 border-x border-line bg-transparent text-center text-label-16 text-ink outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        value={typed}
                        onChange={(event) => setTyped(toLatinDigits(event.target.value).replace(/[^0-9]/g, ''))}
                        onBlur={() => (full ? undefined : setTyped(String(Math.min(maximum ?? Number.MAX_SAFE_INTEGER, Math.max(minimum, Number.isInteger(quantity) && quantity > 0 ? quantity : minimum)))))}
                        data-test="add-quantity"
                    />
                    <Button type="button" variant="ghost" size="icon" className="h-full w-10 rounded-none" aria-label={t(`${C}.add.more`)} disabled={maximum !== null && Number.isInteger(quantity) && quantity >= maximum} onClick={() => step(1)} data-test="add-more">
                        <Plus aria-hidden="true" />
                    </Button>
                </div>
                <ActionButton size="lg" className="h-12 min-w-0 flex-1" loading={busy} disabledReason={blocked ?? (variant !== null && outside ? limits : undefined)} onClick={add} data-test="add">
                    {t(`${C}.add.add`)}
                </ActionButton>
                {heart}
            </div>

            <p id="add-limits" className="tw-figure text-copy-13 text-ink-muted" data-test="add-limits">
                {limits}
            </p>
            {unit === null || outside ? null : (
                <p className="flex flex-wrap items-baseline gap-1.5 text-copy-14 text-ink" data-test="add-total">
                    {t(`${C}.add.total`)}
                    <Money value={moneyTimes(unit, quantity)} className="font-semibold" />
                    {t(`${C}.add.before_vat`)}
                </p>
            )}

            {blocked === undefined ? null : (
                <p className="text-copy-14 text-ink" role="status" data-test={full ? 'line-full' : 'out-of-stock'}>
                    {blocked}
                </p>
            )}
            {size === null || size.inCart === 0 ? null : (
                <p className="text-copy-13 text-ink-muted" data-test="in-cart">
                    {plural(t, locale, `${C}.add.in_cart`, size.inCart)}
                </p>
            )}
            {pointed && variant === null ? (
                <p className="text-copy-14 text-ink" role="status" data-test="choose-size">
                    {t(`${C}.add.choose`)}
                </p>
            ) : null}
        </section>
    );
}

/** The wholesale steps: from the first piece sold wholesale, then from each band's start above it. */
function stepsOf(wholesale: ShopWholesaleSizeData, first: number): { from: number; unit: MoneyData }[] {
    return [
        { from: first, unit: wholesale.bands.filter((band) => band.minQuantity <= first).pop()?.unit ?? wholesale.price },
        ...wholesale.bands.filter((band) => band.minQuantity > first).map((band) => ({ from: band.minQuantity, unit: band.unit })),
    ];
}

/** What a piece costs on a wholesale line holding this many: the last step it reaches. */
function unitAt(wholesale: ShopWholesaleSizeData, first: number, pieces: number): MoneyData {
    const steps = stepsOf(wholesale, first);

    return steps.filter((step) => step.from <= pieces).pop()?.unit ?? steps[0]?.unit ?? wholesale.price;
}

/**
 * The wholesale prices by quantity: from the first piece sold wholesale - at the band that starts there or below,
 * else the size's own price - then from each band's start above it; the step the quantity typed falls in, highlighted.
 */
function Bands({ wholesale, first, quantity }: { wholesale: ShopWholesaleSizeData; first: number; quantity: number | null }) {
    const t = useTranslator();
    const locale = useLocale();
    const steps = stepsOf(wholesale, first);
    const applies = quantity === null ? -1 : steps.reduce((at, step, index) => (step.from <= quantity ? index : at), -1);

    return (
        <section className="grid gap-1" aria-label={t(`${C}.bands.title`)} data-test="bands">
            <h2 className="text-label-14 font-semibold text-ink">{t(`${C}.bands.title`)}</h2>
            <ul className="flex flex-wrap gap-2">
                {steps.map((step, index) => (
                    <li
                        key={step.from}
                        aria-current={index === applies ? 'true' : undefined}
                        className={cn('rounded-md border px-2 py-1 text-copy-13', index === applies ? 'border-ink bg-surface-sunken text-ink' : 'border-line text-ink-muted')}
                        data-test={index === applies ? 'band-current' : 'band'}
                    >
                        {plural(t, locale, `${C}.bands.from`, step.from)}: <Money value={step.unit} className="font-semibold" />
                    </li>
                ))}
            </ul>
        </section>
    );
}
