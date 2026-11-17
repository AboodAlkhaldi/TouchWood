import { type ReactNode, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from 'cn';
import { ChevronLeft, RotateCcw, Truck } from 'lucide-react';
import { Note } from '@/components/Note';
import { Money } from '@/components/Money';
import { isReduced, ThemeRibbon } from '@/components/content/ThemeMarks';
import { type FeedbackSections, QuestionsSection, RatingLine, ReviewsSection, SaveHeart } from '@/components/feedback/shop';
import { AddToCart, ModeSwitch, useSaleModes } from '@/components/sales/AddToCart';
import { type CardFacts, CompactRows, FlagBadge, flagsOf, ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { RichText } from '@/components/shop/RichText';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { percentOff } from '@/components/shop/ShopPrice';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { moneyAs } from '@/lib/money';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { ShopChoiceData, ShopProductPage, ShopVariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { MoneyData } from '@/types/generated/Modules/Platform/Public/Dto';
import type { ShopWholesaleData, ShopWholesaleSizeData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { ShopCartData } from '@/types/generated/Modules/Sales/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| A product's page in the shop (catalog.md §4.5), to the design (shop-design.md §6): the gallery beside a 460 px details
| column, 44 px apart - a 4:3 photo showing the whole product, four thumbnails under it, the theme's ribbon over it while
| the chosen size shows a reduced price. Then the brand and every flag, the 30 px name, the rating, the price box - the
| price at 36 px with its unit, the tax note, the wholesale note and the Retail / Wholesale switch - the choices as
| chips, the chosen one filled navy (a value no size left holds disabled, a sold-out size greyed, amendment 39; "Last
| pieces" on the ending-soon option, amendment 16(h)), how it is sold (P17), Sales's buy row with the heart, the total,
| and the delivery and returns lines. Below: Description, Specifications and Returns & warranty as tabs (sections that
| fold open on a phone), Goes With as compact rows, the reviews and questions, You May Also Like as cards. No code.
| The variant is matched here from the list the server sent, for display only - Sales resolves it on the server when
| there is a cart (handoff §9.1). On a phone: a swipe gallery with «1/4», back and the heart over it, the buy row fixed
| at the bottom with the tab bar hidden (§6.6).
|
| Not available now (P18): its name, photos and description, a Note, nothing to choose; only sold out or
| unpriced, its rating, reviews and questions stay, with no Add to Cart (amendment 40(a)).
|
| Other modules' sections (amendment 26(b)) are drawn in their slots: Feedback's rating under the name, its Save
| heart in the buy row - alone on a "Not available now" page (amendment 34) - its reviews and answered questions after
| Goes With (feedback.md §4.1 F5, Q11).
*/

/** The variant these choices make, or none. */
function variantOf(variants: ShopVariantData[], choices: ShopChoiceData[], chosen: Record<string, string>): ShopVariantData | null {
    return variants.find((variant) => choices.every((choice) => variant.values[choice.id] === chosen[choice.id])) ?? null;
}

/** Picking a value keeps the other choices where a variant on sale has them all, else the first that holds it. */
function pick(variants: ShopVariantData[], choices: ShopChoiceData[], chosen: Record<string, string>, attributeId: string, valueId: string): ShopVariantData | null {
    return variantOf(variants, choices, { ...chosen, [attributeId]: valueId }) ?? variants.find((variant) => variant.values[attributeId] === valueId) ?? null;
}

/** Sales's section (sales.md §4.1 S2): Add to Cart offered on this page; Pricing's, the wholesale sizes' prices and bands (amendment 35(d)). */
type SalesSections = { 'sales.cart'?: ShopCartData | null; 'pricing.wholesale'?: ShopWholesaleData | null };

export default function Product(page: ShopProductPage & { sections: FeedbackSections & SalesSections; cardFacts?: CardFacts | unknown[] }) {
    const t = useTranslator();
    const link = useLink();
    const { frame } = usePage<SharedProps>().props;
    const { name, available, choices, selling, warranty, address } = page;
    // A sold-out or unpriced size is never chosen, opened on or priced: only the sizes that can be ordered are offered (amendment 39).
    // A page kept from before this has no such field: its sizes stay (`!== false`).
    const variants = page.variants.filter((each) => each.orderable !== false);
    const [chosen, setChosen] = useState<Record<string, string>>(() => variants[0]?.values ?? {});
    const variant = variantOf(variants, choices, chosen);
    const photos: PhotoSizes[] = variant !== null && variant.photos.length > 0 ? variant.photos : page.photos;
    const sections = page.sections ?? {};
    const facts = useCardFacts(page.cardFacts ?? []);
    const url = link('storefront.catalog.product', { slug: page.slug });
    const cart = sections['sales.cart'] ?? null;
    const wholesale = sections['pricing.wholesale'] ?? null;
    const { modes, mode, setMode } = useSaleModes(variant, available ? selling : null, cart);
    const reduced = isReduced(variant?.price, variant?.priceBefore);
    // Every flag (§6.3): the campaign's word on a reduced price, Last pieces on the chosen size, Best seller, New arrival, the labels.
    const flags = flagsOf({ lastPieces: choices.length === 0 && Boolean(variant?.lastPieces), bestSeller: page.bestSeller, newArrival: page.newArrival, labels: page.labels }, reduced, frame?.content?.theme?.badge ?? null, t);
    const above = page.path.at(-1);
    const back = above ? link('storefront.catalog.category', { slug: above.slug }) : link('storefront.home');
    // The buy row's heart; on a phone the photo carries it (§6.6).
    const heart = (
        <span className="max-sm:hidden">
            <SaveHeart wishlist={sections['feedback.wishlist']} productId={page.productId} name={name} />
        </span>
    );
    const buying = available && selling !== null && cart !== null;
    const hasDescription = Object.keys(page.description).length > 0;
    const parts = [
        ...(hasDescription ? [{ key: 'description', title: t('catalog::shop.product.description'), body: <RichText document={page.description} className="max-w-3xl" /> }] : []),
        ...(variant !== null && hasDetails(variant) ? [{ key: 'specs', title: t('catalog::shop.product.specs'), body: <Details variant={variant} /> }] : []),
        { key: 'returns', title: t('catalog::shop.product.returns_warranty'), body: <ReturnsAndWarranty warranty={warranty} returnDays={page.returnDays} /> },
    ];
    // The open tab; a size without details takes Specifications away, and the first part shows instead.
    const [tab, setTab] = useState<string>(parts[0]?.key ?? 'returns');
    const shownTab = parts.some((part) => part.key === tab) ? tab : (parts[0]?.key ?? 'returns');

    return (
        <StorefrontLayout title={name} address={address} tabBar={!buying}>
            <div className="grid gap-8 sm:gap-10">
                <div className="max-sm:hidden">
                    <ShopTrail path={page.path} current={name} />
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_460px] lg:gap-11">
                    {/* A new set of photos starts at its first. */}
                    <Gallery key={variant?.id ?? 'product'} photos={photos} name={name} reduced={reduced} back={back} heart={<SaveHeart wishlist={sections['feedback.wishlist']} productId={page.productId} name={name} className="size-10 rounded-full border-0 bg-white text-on-photo-ink shadow-sm hover:bg-white/90" test="gallery-heart" />} />

                    <div className="grid content-start gap-4">
                        <div className="flex flex-wrap items-center gap-2">
                            {page.brand !== null ? (
                                <Link href={link('storefront.catalog.brand', { slug: page.brand.slug })} className="text-label-14 text-ink-muted hover:underline" data-test="brand-link">
                                    {page.brand.name}
                                </Link>
                            ) : null}
                            {available && flags.length > 0 ? (
                                <span className="flex flex-wrap gap-1" data-test="product-flags">
                                    {flags.map((flag) => (
                                        <FlagBadge key={flag.key} flag={flag} large />
                                    ))}
                                </span>
                            ) : null}
                        </div>
                        <h1 className="text-[26px] leading-tight font-semibold text-ink sm:text-[30px]">{name}</h1>
                        <RatingLine reviews={sections['feedback.reviews']} />

                        {available ? (
                            <section className="grid gap-2 rounded-xl border border-line bg-surface p-4" data-test="price-box">
                                <ProductPrice variant={variant} wholesale={wholesale} wholesaleMinimum={selling?.wholesaleMinimum ?? null} unit={page.unit} mode={mode} />
                                <p className="text-copy-13 text-ink-muted">{t('catalog::shop.product.tax_note')}</p>
                                {/* A company or a guest who may buy it wholesale: from how many; an individual, whose terms carry no
                                    wholesale side (P17): that companies can. */}
                                {variant?.sellsWholesale && selling !== null ? (
                                    selling.wholesaleMinimum !== null ? (
                                        modes.includes('WHOLESALE') ? (
                                            <p className="tw-figure text-copy-13 text-ink-muted" data-test="wholesale-note">
                                                {t('catalog::shop.product.wholesale_note', { count: selling.wholesaleMinimum })}
                                            </p>
                                        ) : null
                                    ) : (
                                        <p className="text-copy-13 text-ink-muted" data-test="wholesale-note">
                                            {t('catalog::shop.card.wholesale_individual')}
                                        </p>
                                    )
                                ) : null}
                                <ModeSwitch modes={modes} mode={mode} onChange={setMode} />
                            </section>
                        ) : (
                            <Note variant="warning" label={t('catalog::shop.product.not_available')} data-test="not-available">
                                {t('catalog::shop.product.not_available_text')}
                            </Note>
                        )}

                        <div id="product-choices" className="grid gap-5">
                            {choices.map((choice) => (
                                <fieldset key={choice.id} className="grid gap-2" data-test="choice">
                                    <legend className="mb-2 text-label-14 font-semibold text-ink">{choice.name}</legend>
                                    <div className="flex flex-wrap gap-2">
                                        {choice.values.map((value) => {
                                            const becomes = value.onSale ? pick(variants, choices, chosen, choice.id, value.id) : null;
                                            const isChosen = chosen[choice.id] === value.id;
                                            // With the other choices as they are, this value names a sold-out size: greyed. A size left that holds it can still be picked.
                                            const soldOutHere = becomes !== null && variantOf(page.variants, choices, { ...chosen, [choice.id]: value.id })?.orderable === false;

                                            return (
                                                <button
                                                    key={value.id}
                                                    type="button"
                                                    disabled={becomes === null}
                                                    aria-pressed={isChosen}
                                                    aria-label={becomes === null ? t('catalog::shop.product.unavailable_value', { name: value.name }) : soldOutHere ? t('catalog::shop.product.other_option_value', { name: value.name }) : undefined}
                                                    onClick={() => becomes !== null && setChosen(becomes.values)}
                                                    className={cn(
                                                        'flex items-center gap-2 rounded-full border border-line bg-surface px-4 py-2.5 text-label-14 text-ink hover:border-ink disabled:cursor-not-allowed disabled:text-ink-muted disabled:line-through',
                                                        soldOutHere && 'text-ink-muted line-through',
                                                        isChosen && 'border-brand bg-brand text-ink-on-brand hover:border-brand',
                                                    )}
                                                    data-test="choice-value"
                                                >
                                                    {value.swatch !== null ? <span className="size-4 rounded-full shadow-[0_0_0_1px_var(--tw-line-strong)]" style={{ backgroundColor: value.swatch }} aria-hidden="true" /> : null}
                                                    {value.name}
                                                    {/* On the option that is ending soon only (amendment 16(h)). */}
                                                    {becomes?.lastPieces && !soldOutHere ? (
                                                        <Badge className={tone('amber')} data-test="last-pieces">
                                                            {t('catalog::shop.last_pieces')}
                                                        </Badge>
                                                    ) : null}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </fieldset>
                            ))}
                        </div>

                        {available && variant !== null && selling !== null ? (
                            <section className="grid gap-1" data-test="how-sold">
                                <h2 className="text-label-14 font-semibold text-ink">{t('catalog::shop.product.how_sold')}</h2>
                                {variant.sellsRetail ? <Terms side={t('catalog::shop.product.retail')} minimum={selling.retailMinimum} maximum={selling.retailMaximum} /> : null}
                                {variant.sellsWholesale && selling.wholesaleMinimum !== null ? (
                                    <Terms side={t('catalog::shop.product.wholesale')} minimum={selling.wholesaleMinimum} maximum={selling.wholesaleMaximum} />
                                ) : null}
                            </section>
                        ) : null}

                        {/* Sales's Add to Cart (sales.md §4.1 S2), the heart at its row's end; on a "Not available now" page the heart alone (amendment 34). */}
                        {buying && selling !== null ? (
                            <AddToCart variant={variant} selling={selling} choicesId="product-choices" mode={mode} modes={modes} cart={cart} wholesalePrices={variant !== null ? (wholesale?.sizes[variant.id] ?? null) : null} heart={heart} />
                        ) : (
                            heart
                        )}

                        {available ? (
                            <ul className="grid gap-2 border-t border-line pt-4 text-copy-14 text-ink" data-test="product-lines">
                                {page.deliveryDays > 0 ? (
                                    <li className="flex items-center gap-2" data-test="delivery-line">
                                        <Truck aria-hidden="true" className="size-4 text-ink-muted" />
                                        <span className="tw-figure">{t('catalog::shop.product.delivery', { count: page.deliveryDays })}</span>
                                    </li>
                                ) : null}
                                {page.returnDays > 0 ? (
                                    <li className="flex items-center gap-2" data-test="returns-line">
                                        <RotateCcw aria-hidden="true" className="size-4 text-ink-muted" />
                                        <span className="tw-figure">{t('catalog::shop.product.returns', { count: page.returnDays })}</span>
                                    </li>
                                ) : null}
                            </ul>
                        ) : null}
                    </div>
                </div>

                {/* Tabs from a tablet up; on a phone the same parts fold open (shop-design.md §6.5, §6.6). */}
                <Tabs value={shownTab} onValueChange={setTab} className="hidden gap-4 md:flex" data-test="product-tabs">
                    <TabsList className="h-11 w-fit">
                        {parts.map((part) => (
                            <TabsTrigger key={part.key} value={part.key} className="px-4" data-test={`tab-${part.key}`}>
                                {part.title}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {parts.map((part) => (
                        <TabsContent key={part.key} value={part.key} className="pt-2">
                            {part.body}
                        </TabsContent>
                    ))}
                </Tabs>
                <Accordion type="multiple" defaultValue={parts[0] ? [parts[0].key] : []} className="md:hidden" data-test="product-folds">
                    {parts.map((part) => (
                        <AccordionItem key={part.key} value={part.key}>
                            <AccordionTrigger className="text-label-16 font-semibold">{part.title}</AccordionTrigger>
                            <AccordionContent>{part.body}</AccordionContent>
                        </AccordionItem>
                    ))}
                </Accordion>

                {page.goesWith.length > 0 ? (
                    <section className="grid gap-4" data-test="goes-with">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.goes_with')}</h2>
                        <CompactRows cards={page.goesWith} />
                    </section>
                ) : null}

                {sections['feedback.reviews'] ? <ReviewsSection reviews={sections['feedback.reviews']} url={url} productId={page.productId} /> : null}
                {sections['feedback.questions'] ? <QuestionsSection questions={sections['feedback.questions']} url={url} productId={page.productId} star={sections['feedback.reviews']?.star ?? null} /> : null}

                {page.mayAlsoLike.length > 0 ? (
                    <section className="grid gap-4" data-test="may-also-like">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.may_also_like')}</h2>
                        <ProductCards cards={page.mayAlsoLike} facts={facts} />
                    </section>
                ) : null}
            </div>
        </StorefrontLayout>
    );
}

/**
 * A 4:3 photo showing the whole product and four thumbnails under it; a live theme's ribbon on it while the chosen size
 * shows a reduced price (its flag is in the details' flags, §6.2). On a phone a swipe gallery with «1/4», back and the
 * heart over it (§6.6, P22).
 */
function Gallery({ photos, name, reduced, back, heart }: { photos: PhotoSizes[]; name: string; reduced: boolean; back: string; heart: ReactNode }) {
    const t = useTranslator();
    const [shown, setShown] = useState(0);
    const all = photos.length > 0 ? photos : [null];

    return (
        <div className="grid min-w-0 content-start gap-3 max-sm:-mx-4" data-test="gallery">
            <div className="relative overflow-hidden bg-surface-sunken sm:rounded-xl">
                {/* A computer's and a tablet's: the photo the thumbnails choose. */}
                <ShopPhoto photo={all[shown] ?? null} size="detail" alt={name} eager className="aspect-[4/3] w-full object-contain max-sm:hidden" />
                {/* A phone's: the photos side by side, swiped. */}
                <div
                    className="flex snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] sm:hidden"
                    onScroll={(event) => {
                        const box = event.currentTarget;
                        setShown(Math.min(all.length - 1, Math.max(0, Math.round(Math.abs(box.scrollLeft) / box.clientWidth))));
                    }}
                    data-test="gallery-swipe"
                >
                    {all.map((photo, index) => (
                        <ShopPhoto key={index} photo={photo} size="detail" alt={index === 0 ? name : ''} eager={index === 0} className="aspect-[4/3] w-full shrink-0 snap-center object-contain" />
                    ))}
                </div>
                <ThemeRibbon reduced={reduced} />
                <span className="absolute inset-x-3 top-3 flex items-center justify-between sm:hidden">
                    <Link href={back} aria-label={t('ui.back')} className="grid size-10 place-items-center rounded-full bg-white text-on-photo-ink shadow-sm" data-test="gallery-back">
                        <ChevronLeft aria-hidden="true" className="size-5 rtl:rotate-180" />
                    </Link>
                    {heart}
                </span>
                {all.length > 1 ? (
                    <span className="tw-figure absolute end-3 bottom-3 rounded-full bg-black/60 px-2.5 py-0.5 text-label-12 text-white sm:hidden" data-test="gallery-count">
                        {t('catalog::shop.product.photo_count', { number: shown + 1, total: all.length })}
                    </span>
                ) : null}
            </div>
            {photos.length > 1 ? (
                <div className="flex gap-2 overflow-x-auto max-sm:hidden">
                    {photos.map((photo, index) => (
                        <button
                            key={index}
                            type="button"
                            onClick={() => setShown(index)}
                            aria-label={t('catalog::shop.product.photo', { number: index + 1, total: photos.length })}
                            aria-pressed={index === shown}
                            className={cn('w-[calc((100%-1.5rem)/4)] shrink-0 overflow-hidden rounded-lg bg-surface-sunken', index === shown ? 'shadow-[0_0_0_2px_var(--tw-brand)]' : 'shadow-[0_0_0_1px_var(--tw-line)]')}
                        >
                            <ShopPhoto photo={photo} size="thumb" alt="" className="aspect-[4/3] w-full object-contain" />
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function Terms({ side, minimum, maximum }: { side: string; minimum: number; maximum: number | null }) {
    const t = useTranslator();

    return (
        <p className="text-copy-14 text-ink">
            <span className="font-medium">{side}</span>:{' '}
            <span className="tw-figure">
                {t('catalog::shop.product.at_least', { count: minimum })}
                {maximum !== null ? `, ${t('catalog::shop.product.at_most', { count: maximum })}` : ''}
            </span>
        </p>
    );
}

/**
 * The price box's price (§6.3, §4.2 at its size): 36 px with its unit; on a sale in red, the old price readable under it
 * with its percent off and «وفّر» what a piece saves. Wholesale chosen, or a size sold wholesale only: Pricing's, what a
 * piece costs at the wholesale minimum - the band that starts there or below, else its price below any band.
 */
function ProductPrice({ variant, wholesale, wholesaleMinimum, unit, mode }: { variant: ShopVariantData | null; wholesale: ShopWholesaleData | null; wholesaleMinimum: number | null; unit: string; mode: string }) {
    const t = useTranslator();

    if (variant === null) {
        return null;
    }

    const only: ShopWholesaleSizeData | null = wholesale?.sizes[variant.id] ?? null;

    if (variant.price !== null && (mode !== 'WHOLESALE' || only === null)) {
        return <BigPrice now={variant.price} before={variant.priceBefore} unit={unit} />;
    }

    if (only === null) {
        return null;
    }

    // On a sale, the old price struck through beside it, as a retail size's (amendment 45): the band's own, or the size's.
    const band = only.bands.filter((each) => each.minQuantity <= Math.max(1, wholesaleMinimum ?? 1)).pop() ?? null;

    return (
        <span className="grid gap-1" data-test="wholesale-price">
            <span className="text-label-13 text-ink-muted">{t('catalog::shop.product.wholesale')}</span>
            <BigPrice now={band?.unit ?? only.price} before={band === null ? only.priceBefore : band.unitBefore} unit={unit} />
        </span>
    );
}

function BigPrice({ now, before, unit }: { now: MoneyData; before: MoneyData | null; unit: string }) {
    const t = useTranslator();
    const off = percentOff(now, before);

    return (
        <span className="grid gap-1" data-test="price">
            <span className="flex flex-wrap items-baseline gap-x-2">
                <Money value={now} className={cn('text-[30px] leading-tight font-semibold sm:text-[36px]', off > 0 ? 'text-bad' : 'text-brand')} />
                <span className="text-copy-14 text-ink-muted">/ {t(`catalog::shop.unit.${unit}`)}</span>
            </span>
            {before !== null && off > 0 ? (
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <s className="text-[16px] text-ink-muted decoration-[1.5px] sm:text-[18px]" data-test="price-before">
                        <span className="sr-only">{t('catalog::shop.price.was')} </span>
                        <Money value={before} />
                    </s>
                    <Badge className={tone('red')} data-test="price-off">
                        <bdi dir="ltr" className="tw-figure">
                            {t('catalog::shop.price.off', { percent: off })}
                        </bdi>
                    </Badge>
                    <span className="text-copy-14 font-medium text-good" data-test="price-saving">
                        {t('catalog::shop.saving', { amount: moneyAs(now, BigInt(before.minor) - BigInt(now.minor)).text })}
                    </span>
                </span>
            ) : null}
        </span>
    );
}

function hasDetails(variant: ShopVariantData): boolean {
    return variant.details.length > 0 || variant.weightGrams !== null || (variant.lengthMm !== null && variant.widthMm !== null && variant.heightMm !== null);
}

/** The chosen variant's details, weight and size, in a table. */
function Details({ variant }: { variant: ShopVariantData }) {
    const t = useTranslator();
    const rows: [string, string][] = variant.details.map((detail) => [detail.name, detail.value]);

    if (variant.weightGrams !== null) {
        rows.push([t('catalog::shop.product.weight'), t('catalog::shop.product.grams', { value: variant.weightGrams })]);
    }

    if (variant.lengthMm !== null && variant.widthMm !== null && variant.heightMm !== null) {
        rows.push([t('catalog::shop.product.size'), t('catalog::shop.product.size_value', { length: variant.lengthMm, width: variant.widthMm, height: variant.heightMm })]);
    }

    return (
        <section className="grid max-w-3xl gap-2" data-test="details">
            <Table>
                <TableBody>
                    {rows.map(([label, value]) => (
                        <TableRow key={label}>
                            <TableHead className="w-1/3 text-ink-muted">{label}</TableHead>
                            <TableCell className="tw-figure text-ink">{value}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </section>
    );
}

/** The warranty's name, period and terms, and the returns line (§6.5). */
function ReturnsAndWarranty({ warranty, returnDays }: { warranty: ShopProductPage['warranty']; returnDays: number }) {
    const t = useTranslator();

    return (
        <div className="grid max-w-3xl gap-3">
            {warranty !== null ? (
                <section className="grid gap-2" data-test="warranty">
                    <h3 className="text-label-14 font-semibold text-ink">{t('catalog::shop.product.warranty')}</h3>
                    <p className="text-copy-16 text-ink">
                        {warranty.name} ·{' '}
                        <span className="tw-figure">
                            {warranty.periodMonths === null
                                ? t('catalog::shop.product.lifetime')
                                : warranty.periodMonths === 1
                                  ? t('catalog::shop.product.one_month')
                                  : t('catalog::shop.product.months', { count: warranty.periodMonths })}
                        </span>
                    </p>
                    <RichText document={warranty.terms} className="text-copy-14" />
                </section>
            ) : (
                <p className="text-copy-14 text-ink-muted">{t('catalog::shop.product.no_warranty')}</p>
            )}
            {returnDays > 0 ? <p className="tw-figure text-copy-14 text-ink">{t('catalog::shop.product.returns', { count: returnDays })}</p> : null}
        </div>
    );
}
