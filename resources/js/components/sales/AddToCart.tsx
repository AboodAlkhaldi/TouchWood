import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Money } from '@/components/Money';
import { TextField } from '@/components/Fields';
import { plural, useLocale } from '@/components/sales/shop';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/cn';
import { figure, toLatinDigits } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { ShopSellingData, ShopVariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { ShopWholesaleSizeData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { ShopCartData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';

/*
| S2 - Add to Cart on a product's page, never on a card (sales.md §4.1; R1-9): Retail or Wholesale above the
| quantity for a viewer the page sells both to, each with its own limits (R1-7); the quantity starting at the
| minimum and kept within the limits (R1-8). Pressed before a size is chosen, it points at the choices and adds
| nothing (R2-6). Added, it says so with View Cart for a few seconds (R2-7). A size out of stock, or not for this
| account, the cart's command refuses in its own words (R1-2). Drawn only where Sales offers it: never on a "Not
| available now" page (catalog.md §1.4). Under the Wholesale choice, the size's wholesale prices - from its
| first piece, then from each band's start - the one the quantity typed buys at highlighted (S1; R2-3).
|
| Once a size is chosen, what this viewer may add of it is Sales's own (§4.2 B15): the modes it sells to them now,
| each mode's limits counted with the pieces its line already holds - an add joins that line - and Out of
| stock when too few are free for the least add; the pieces of it already in the cart said under the box.
*/

type Mode = 'RETAIL' | 'WHOLESALE';

/** Its own words: a product's page carries these, not the cart's. */
const C = 'sales::shop_add';

export function AddToCart({
    variant,
    selling,
    choicesId,
    wholesalePrices = null,
    cart = null,
}: {
    variant: ShopVariantData | null;
    selling: ShopSellingData;
    choicesId: string;
    /** Pricing's prices for the chosen size, where it sells wholesale to this viewer. */
    wholesalePrices?: ShopWholesaleSizeData | null;
    /** Sales's section: what this viewer may add of each size. */
    cart?: ShopCartData | null;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    // The chosen size as Sales offers it; none before a size is chosen, when the page's terms stand in.
    const size = variant === null || cart === null ? null : (cart.sizes.find((each) => each.variantId === variant.id) ?? null);
    const known = variant === null || cart === null || size !== null;
    const wholesale = !known ? false : size !== null ? size.wholesale !== null : variant === null ? selling.wholesaleMinimum !== null : variant.sellsWholesale && selling.wholesaleMinimum !== null;
    const retail = !known ? false : size !== null ? size.retail !== null : variant === null ? true : variant.sellsRetail;
    const modes: Mode[] = [...(retail ? (['RETAIL'] as const) : []), ...(wholesale ? (['WHOLESALE'] as const) : [])];
    const [mode, setMode] = useState<Mode>(modes[0] ?? 'RETAIL');
    const line = size === null ? null : mode === 'WHOLESALE' ? size.wholesale : size.retail;
    // An add joins the mode's line: the line's limits cover what it holds and the add together (B15).
    const minimum = line !== null ? Math.max(1, line.minimum - line.inCart) : Math.max(1, mode === 'WHOLESALE' ? (selling.wholesaleMinimum ?? 1) : selling.retailMinimum);
    const maximum = line !== null ? (line.maximum === null ? null : line.maximum - line.inCart) : mode === 'WHOLESALE' ? selling.wholesaleMaximum : selling.retailMaximum;
    const full = maximum !== null && maximum < minimum;
    const outOfStock = size !== null && (size.outOfStock || (line?.outOfStock ?? false));
    const [typed, setTyped] = useState(String(minimum));
    const [busy, setBusy] = useState(false);
    const [added, setAdded] = useState<number | null>(null);
    const [pointed, setPointed] = useState(false);

    // Another mode, or another size that does not sell in this one: back to its first piece count.
    useEffect(() => {
        if (!modes.includes(mode) && modes[0]) {
            setMode(modes[0]);
        }
    }, [modes, mode]);
    useEffect(() => setTyped(String(minimum)), [minimum]);
    useEffect(() => {
        if (added === null) {
            return;
        }

        const timer = window.setTimeout(() => setAdded(null), 6000);

        return () => window.clearTimeout(timer);
    }, [added]);

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

                if (Object.keys(errors).length === 0) {
                    setAdded(quantity);
                }
            },
        });
    };

    return (
        <section className="grid gap-3" data-test="add-to-cart">
            {modes.length > 1 ? (
                <FieldSet className="gap-2">
                    <FieldLegend variant="label">{t(`${C}.add.mode`)}</FieldLegend>
                    <ToggleGroup
                        type="single"
                        value={mode}
                        onValueChange={(next) => (next === 'RETAIL' || next === 'WHOLESALE' ? setMode(next) : undefined)}
                        aria-label={t(`${C}.add.mode`)}
                        className={`${SWITCH_TRACK} w-fit`}
                        data-test="sale-mode"
                    >
                        {modes.map((each) => (
                            <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM} data-test={`mode-${each}`}>
                                {t(`${C}.mode.${each}`)}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </FieldSet>
            ) : null}

            {mode === 'WHOLESALE' && wholesalePrices !== null ? <Bands wholesale={wholesalePrices} first={line?.minimum ?? minimum} quantity={outside ? null : quantity + (line?.inCart ?? 0)} /> : null}

            <div className="flex flex-wrap items-end gap-3">
                <TextField
                    id="add-quantity"
                    className="w-32"
                    inputMode="numeric"
                    dir="ltr"
                    inputClassName="tw-figure"
                    label={t(`${C}.add.quantity`)}
                    helper={limits}
                    value={typed}
                    onChange={(event) => setTyped(toLatinDigits(event.target.value).replace(/[^0-9]/g, ''))}
                    onBlur={() => (full ? undefined : setTyped(String(Math.min(maximum ?? Number.MAX_SAFE_INTEGER, Math.max(minimum, Number.isInteger(quantity) && quantity > 0 ? quantity : minimum)))))}
                    data-test="add-quantity"
                />
                <ActionButton size="lg" loading={busy} disabledReason={blocked ?? (variant !== null && outside ? limits : undefined)} onClick={add} data-test="add">
                    {t(`${C}.add.add`)}
                </ActionButton>
            </div>

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
            {added === null ? null : (
                <p className="flex flex-wrap items-center gap-2 text-copy-14 text-ink" role="status" data-test="added">
                    {t(`${C}.add.added`, { pieces: plural(t, locale, `${C}.pieces`, added) })}
                    <Link href={link('storefront.sales.cart')} className="underline underline-offset-4" data-test="view-cart">
                        {t(`${C}.add.view`)}
                    </Link>
                </p>
            )}
        </section>
    );
}

/**
 * The wholesale prices by quantity: from the first piece sold wholesale - at the band that starts there or below,
 * else the size's own price - then from each band's start above it; the step the quantity typed falls in, highlighted.
 */
function Bands({ wholesale, first, quantity }: { wholesale: ShopWholesaleSizeData; first: number; quantity: number | null }) {
    const t = useTranslator();
    const locale = useLocale();
    const steps: { from: number; unit: MoneyData }[] = [
        { from: first, unit: wholesale.bands.filter((band) => band.minQuantity <= first).pop()?.unit ?? wholesale.price },
        ...wholesale.bands.filter((band) => band.minQuantity > first).map((band) => ({ from: band.minQuantity, unit: band.unit })),
    ];
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
