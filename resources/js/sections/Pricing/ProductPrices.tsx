import { lazy, Suspense, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Money } from '@/components/Money';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { BandData, PickedProductData, SaleRowData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { StoreSectionProps } from '@/pages/Catalog/Admin/Products/store-sections';
import { PriceBox, typedOf, useBoxes } from '@/pages/Pricing/Admin/boxes';
import { offText } from '@/pages/Pricing/Admin/discount-parts';
import { OfferMenu, useOfferDialogs } from '@/pages/Pricing/Admin/OfferActions';
import { ltr, Mark, nameIn, percentOf, plural, PricingWords, StateBadge, useDate, useLocale, useT, type Locale } from '@/pages/Pricing/Admin/parts';

// The bands dialog loads when it is first opened, not with the tab.
const BandsDialog = lazy(() => import('@/pages/Pricing/Admin/BandsDialog').then((module) => ({ default: module.BandsDialog })));

/*
| **Pricing's part of a store card** on a product's Stores tab (pricing.md §4.3.3, the product page; Catalog's
| amendment 23(e)), for whoever holds a Pricing job in that store: each size's retail price - a box for whoever
| edits prices where no provider sets them -, what one piece costs today, and its wholesale bands ("3 bands",
| opening the bands dialog for whoever edits wholesale prices); the save bar inside the card; under it the
| product's running and scheduled offers, each with Offers' ⋯, **Add Offer…** (the product chosen), and the
| category discounts reaching it, linked. Its words travel with its data: Catalog's page carries none of
| Pricing's.
*/

type Today = { price: MoneyData; before: MoneyData | null; why: string; alwaysWins: boolean; sourceId: string | null; endsAt: string | null };
type SizePrices = { variantId: string; retail: { price: MoneyData; source: string; updatedAt: string } | null; today: Today | null; bands: BandData[] };
type ProductSale = { id: string; state: string; percentBasisPoints: number | null; salePrice: MoneyData | null; variantIds: string[]; startsAt: string; endsAt: string | null; alwaysWins: boolean };
type ProductDiscount = { id: string; categoryId: string; categoryPath: string[]; state: string; percentBasisPoints: number | null; amountOff: MoneyData | null; lines: string; startsAt: string; endsAt: string | null; alwaysWins: boolean };
type Card = {
    storeId: string;
    mayEditPrices: boolean;
    mayEditWholesale: boolean;
    mayManageDiscounts: boolean;
    wired: boolean;
    sizes: SizePrices[];
    sales: ProductSale[];
    discounts: ProductDiscount[];
    decimals: number;
    currency: string;
    timezone: string;
    words: Record<string, string>;
};

/** What the product page holds that the section reads: the product and its sizes' names. */
type HostPage = {
    product?: { id: string; nameAr: string; nameEn: string | null };
    variants?: { id: string; code: string; archived: boolean; values: { nameAr: string; nameEn: string }[] }[] | null;
};

export default function ProductPrices({ data, store }: StoreSectionProps) {
    const card = data as Card;

    return (
        <PricingWords.Provider value={card.words}>
            <Section card={card} store={{ code: store.code, name: store.name }} />
        </PricingWords.Provider>
    );
}

function sizeLabel(locale: Locale, oneSize: string, values: { nameAr: string; nameEn: string }[]): string {
    return values.length === 0 ? oneSize : values.map((value) => (locale === 'ar' ? value.nameAr : value.nameEn)).join(' · ');
}

function Section({ card, store }: { card: Card; store: { code: string; name: string } }) {
    const t = useT();
    const locale = useLocale();
    const date = useDate(card.timezone);
    const host = usePage().props as unknown as HostPage;
    const [bands, setBands] = useState<{ variantId: string; name: string } | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const offerStore = { code: store.code, decimals: card.decimals, currency: card.currency, zone: card.timezone };
    const offers = useOfferDialogs(offerStore);
    const variants = new Map((host.variants ?? []).map((variant) => [variant.id, variant]));
    const nameOf = (id: string) => sizeLabel(locale, t('pricing::admin.one_size'), variants.get(id)?.values ?? []);
    const editable = card.mayEditPrices && !card.wired;
    const boxes = useBoxes(
        editable ? card.sizes.map((size) => ({ id: size.variantId, saved: size.retail === null ? '' : typedOf(size.retail.price.minor, card.decimals) })) : [],
        // Each store card's boxes their own: ids, refusals and save bar (the review of step 3).
        { store: store.code, decimals: card.decimals, prefix: `price-${store.code}` },
    );
    const productName = host.product === undefined ? '' : nameIn(locale, host.product.nameAr, host.product.nameEn);
    const product: PickedProductData | null = host.product === undefined ? null : { productId: host.product.id, nameAr: host.product.nameAr, nameEn: host.product.nameEn, photo: null, codes: [] };
    // An offer of the product as Offers' ⋯ reads one.
    const asRow = (sale: ProductSale): SaleRowData => ({
        id: sale.id,
        productId: host.product?.id ?? '',
        productNameAr: host.product?.nameAr ?? '',
        productNameEn: host.product?.nameEn ?? null,
        state: sale.state,
        percentBasisPoints: sale.percentBasisPoints,
        salePrice: sale.salePrice,
        startsAt: sale.startsAt,
        endsAt: sale.endsAt,
        createdAt: null,
        alwaysWins: sale.alwaysWins,
        variantIds: sale.variantIds,
        sizeNames: sale.variantIds.map(nameOf),
        allSizes: false,
        sizesOn: sale.variantIds.length,
        productSizesOn: card.sizes.length,
        archivedSince: 0,
        unpricedSizeNames: sale.variantIds.filter((id) => card.sizes.find((size) => size.variantId === id)?.retail === null).map(nameOf),
    });
    const offLine = (sale: ProductSale) => {
        const off = ltr(sale.percentBasisPoints !== null ? `−${percentOf(locale, sale.percentBasisPoints)}%` : (sale.salePrice?.text ?? ''));

        if (sale.state === 'SCHEDULED') {
            return t('pricing::admin.offer.line_scheduled', { off, date: date(sale.startsAt) });
        }

        return sale.endsAt === null ? t('pricing::admin.offer.line_open', { off }) : t('pricing::admin.offer.line', { off, date: date(sale.endsAt) });
    };

    return (
        <div className="grid gap-4" data-test={`pricing-section-${store.code}`}>
            <h3 className="text-heading-14 text-ink">{t('pricing::admin.section.title')}</h3>
            <div className="overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('pricing::admin.section.title')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('pricing::admin.column.size')}</TableHead>
                            <TableHead>{t('pricing::admin.column.retail_price')}</TableHead>
                            <TableHead>{t('pricing::admin.column.today')}</TableHead>
                            <TableHead>{t('pricing::admin.column.wholesale')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {card.sizes.map((size) => {
                            const id = size.variantId;
                            const name = nameOf(id);

                            return (
                                <TableRow key={id} data-test={`section-size-${id}`}>
                                    <TableCell>
                                        <span className="grid">
                                            <span>{name}</span>
                                            <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                {variants.get(id)?.code ?? ''}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {editable ? (
                                            <PriceBox
                                                id={boxes.idOf(id)}
                                                label={t('pricing::admin.box.label_store', { size: name, store: store.name })}
                                                currency={card.currency}
                                                value={boxes.valueOf({ id, saved: size.retail === null ? '' : typedOf(size.retail.price.minor, card.decimals) })}
                                                onChange={(value) => boxes.type(id, value)}
                                                onEscape={() => boxes.reset(id)}
                                                onEnter={boxes.save}
                                                changed={boxes.changed(id)}
                                                error={boxes.error(id)}
                                            />
                                        ) : size.retail !== null ? (
                                            <Money value={size.retail.price} />
                                        ) : (
                                            <span className="text-copy-13 text-ink-muted">{t('pricing::admin.no_price')}</span>
                                        )}
                                        {size.retail !== null ? <span className="mt-1 block text-copy-12 text-ink-muted">{t(`pricing::admin.source.${size.retail.source}`)}</span> : null}
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {size.today === null ? (
                                            '—'
                                        ) : (
                                            <span className="grid gap-1">
                                                {size.today.before !== null ? <Money value={size.today.before} className="text-copy-12 text-ink-muted line-through" /> : null}
                                                <Money value={size.today.price} />
                                                {size.today.why === 'SALE' || size.today.why === 'CATEGORY' ? (
                                                    <span className="flex flex-wrap gap-1">
                                                        <Mark tone="green-subtle">{size.today.why === 'SALE' ? t('pricing::admin.badge.offer') : t('pricing::admin.badge.discount')}</Mark>
                                                        {size.today.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}
                                                    </span>
                                                ) : null}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="align-top">
                                        {size.bands.length === 0 && !card.mayEditWholesale ? (
                                            '—'
                                        ) : card.mayEditWholesale ? (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0 tw-figure"
                                                onClick={(event) => {
                                                    opener.current = event.currentTarget;
                                                    setBands({ variantId: id, name });
                                                }}
                                                data-test={`section-bands-${id}`}
                                            >
                                                {size.bands.length === 0 ? t('pricing::admin.menu.edit_wholesale') : plural(t, locale, 'pricing::admin.bands', size.bands.length)}
                                            </Button>
                                        ) : (
                                            <span className="tw-figure">{plural(t, locale, 'pricing::admin.bands', size.bands.length)}</span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            {boxes.bar(t('pricing::admin.box.save'))}

            <div className="grid gap-2">
                <h4 className="text-label-14 text-ink">{t('pricing::admin.section.offers')}</h4>
                {card.sales.length === 0 ? <p className="text-copy-13 text-ink-muted">{t('pricing::admin.section.no_offers')}</p> : null}
                <ul className="grid gap-1">
                    {card.sales.map((sale) => (
                        <li key={sale.id} className="flex flex-wrap items-center gap-2 text-copy-14" data-test={`section-offer-${sale.id}`}>
                            <StateBadge state={sale.state} />
                            <span className="tw-figure">{offLine(sale)}</span>
                            {sale.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}
                            <OfferMenu sale={asRow(sale)} mayEdit={card.mayEditPrices} name={productName} onOpen={(mode, trigger) => offers.open({ mode, sale: asRow(sale) }, trigger)} />
                        </li>
                    ))}
                </ul>
                {card.mayEditPrices ? (
                    <ActionButton type="button" variant="outline" size="sm" className="w-fit" onClick={(event) => offers.open({ mode: 'add', sale: null, product }, event.currentTarget)} data-test={`section-add-offer-${store.code}`}>
                        {t('pricing::admin.menu.add_offer')}
                    </ActionButton>
                ) : null}
            </div>

            {card.discounts.length > 0 ? (
                <div className="grid gap-2">
                    <h4 className="text-label-14 text-ink">{t('pricing::admin.section.discounts')}</h4>
                    <ul className="grid gap-1">
                        {card.discounts.map((discount) => {
                            const line = t(discount.endsAt === null ? 'pricing::admin.discount_line' : 'pricing::admin.discount_until', {
                                category: discount.categoryPath.join(' › '),
                                off: discount.percentBasisPoints !== null ? t('pricing::admin.percent_off', { percent: ltr(percentOf(locale, discount.percentBasisPoints)) }) : offText(t, locale, discount),
                                date: discount.endsAt === null ? '' : date(discount.endsAt),
                            });

                            return (
                                <li key={discount.id} className="flex flex-wrap items-center gap-2 text-copy-14" data-test={`section-discount-${discount.id}`}>
                                    <StateBadge state={discount.state} />
                                    <Link href={`/admin/category-discounts/${discount.id}`} className="underline underline-offset-4">
                                        {line}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ) : null}

            {offers.dialogs}
            {bands !== null ? (
                <Suspense fallback={null}>
                    <BandsDialog store={{ code: store.code, decimals: card.decimals, currency: card.currency }} size={bands} open onOpenChange={(open) => (open ? undefined : setBands(null))} returnFocusTo={opener} />
                </Suspense>
            ) : null}
        </div>
    );
}
