import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { plural, useLocale } from '@/components/sales/shop';
import { FieldLegend, FieldSet } from '@/components/ui/field';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { ShopSellingData, ShopVariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| S2 - Add to Cart on a product's page, never on a card (sales.md §4.1; R1-9): Retail or Wholesale above the
| quantity for a viewer the page sells both to, each with its own limits (R1-7); the quantity starting at the
| minimum and kept within the limits (R1-8). Pressed before a size is chosen, it points at the choices and adds
| nothing (R2-6). Added, it says so with View Cart for a few seconds (R2-7). A size out of stock, or not for this
| account, the cart's command refuses in its own words (R1-2). Drawn only where Sales offers it: never on a "Not
| available now" page (catalog.md §1.4).
*/

type Mode = 'RETAIL' | 'WHOLESALE';

/** Its own words: a product's page carries these, not the cart's. */
const C = 'sales::shop_add';

export function AddToCart({ variant, selling, choicesId }: { variant: ShopVariantData | null; selling: ShopSellingData; choicesId: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const wholesale = variant === null ? selling.wholesaleMinimum !== null : variant.sellsWholesale && selling.wholesaleMinimum !== null;
    const retail = variant === null ? true : variant.sellsRetail;
    const modes: Mode[] = [...(retail ? (['RETAIL'] as const) : []), ...(wholesale ? (['WHOLESALE'] as const) : [])];
    const [mode, setMode] = useState<Mode>(modes[0] ?? 'RETAIL');
    const minimum = Math.max(1, mode === 'WHOLESALE' ? (selling.wholesaleMinimum ?? 1) : selling.retailMinimum);
    const maximum = mode === 'WHOLESALE' ? selling.wholesaleMaximum : selling.retailMaximum;
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
        return null;
    }

    const quantity = Number(typed);
    const outside = !Number.isInteger(quantity) || quantity < minimum || (maximum !== null && quantity > maximum);
    const limits = maximum === null ? t(`${C}.add.from`, { minimum: figure(locale, minimum) }) : t(`${C}.add.limits`, { minimum: figure(locale, minimum), maximum: figure(locale, maximum) });

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
                    onChange={(event) => setTyped(event.target.value.replace(/[^0-9]/g, ''))}
                    onBlur={() => setTyped(String(Math.min(maximum ?? Number.MAX_SAFE_INTEGER, Math.max(minimum, Number.isInteger(quantity) && quantity > 0 ? quantity : minimum))))}
                    data-test="add-quantity"
                />
                <ActionButton size="lg" loading={busy} disabledReason={variant !== null && outside ? limits : undefined} onClick={add} data-test="add">
                    {t(`${C}.add.add`)}
                </ActionButton>
            </div>

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
