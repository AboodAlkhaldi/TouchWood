import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { cn } from 'cn';
import { Note } from '@/components/Note';
import { type FeedbackSections, QuestionsSection, RatingLine, ReviewsSection, SaveHeart } from '@/components/feedback/shop';
import { ProductCards } from '@/components/shop/ProductCards';
import { RichText } from '@/components/shop/RichText';
import { ShopPhoto, type PhotoSizes } from '@/components/shop/ShopPhoto';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableRow } from '@/components/ui/table';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { ShopChoiceData, ShopProductPage, ShopVariantData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| A product's page in the shop (catalog.md §4.5): the path to its category, the brand, the labels, the
| name; the gallery - the chosen variant's own photos, else the product's; the choices, a row per
| variant attribute in the product's order, its values as buttons in the attribute's order, a colour's
| with its swatch, a value no variant on sale holds disabled; the chosen variant's details, weight and
| size; "Last pieces" on the ending-soon option only (amendment 16(h)); the description; the warranty;
| how it is sold to this viewer (P17); Goes With and You May Also Like. No code, no price, no Add to
| Cart. The variant is matched here from the list the server sent, for display only - Sales resolves
| it on the server when there is a cart (handoff §9.1).
|
| Not available now (P18): its name, photos and description, a Note, nothing to choose.
|
| Other modules' sections (amendment 26(b)) are drawn in their slots: Feedback's rating under the name,
| its Save heart after the choices, its reviews and answered questions after Goes With (feedback.md §4.1
| F5, Q11).
*/

/** The variant these choices make, or none. */
function variantOf(variants: ShopVariantData[], choices: ShopChoiceData[], chosen: Record<string, string>): ShopVariantData | null {
    return variants.find((variant) => choices.every((choice) => variant.values[choice.id] === chosen[choice.id])) ?? null;
}

/** Picking a value keeps the other choices where a variant on sale has them all, else the first that holds it. */
function pick(variants: ShopVariantData[], choices: ShopChoiceData[], chosen: Record<string, string>, attributeId: string, valueId: string): ShopVariantData | null {
    return variantOf(variants, choices, { ...chosen, [attributeId]: valueId }) ?? variants.find((variant) => variant.values[attributeId] === valueId) ?? null;
}

export default function Product(page: ShopProductPage & { sections: FeedbackSections }) {
    const t = useTranslator();
    const link = useLink();
    const { name, available, variants, choices, selling, warranty, address } = page;
    const [chosen, setChosen] = useState<Record<string, string>>(() => variants[0]?.values ?? {});
    const variant = variantOf(variants, choices, chosen);
    const photos: PhotoSizes[] = variant !== null && variant.photos.length > 0 ? variant.photos : page.photos;
    const sections = page.sections ?? {};
    const url = link('storefront.catalog.product', { slug: page.slug });

    return (
        <StorefrontLayout title={name} address={address}>
            <div className="grid gap-8">
                <ShopTrail path={page.path} current={name} />

                <div className="grid gap-8 md:grid-cols-2">
                    {/* A new set of photos starts at its first. */}
                    <Gallery key={variant?.id ?? 'product'} photos={photos} name={name} />

                    <div className="grid content-start gap-4">
                        {page.brand !== null ? (
                            <Link href={link('storefront.catalog.brand', { slug: page.brand.slug })} className="text-label-14 text-ink-muted hover:underline" data-test="brand-link">
                                {page.brand.name}
                            </Link>
                        ) : null}
                        {page.labels.length > 0 ? (
                            <span className="flex flex-wrap gap-1">
                                {page.labels.map((label) => (
                                    <Badge key={label.name} className={tone(label.tone as Tone)}>
                                        {label.name}
                                    </Badge>
                                ))}
                            </span>
                        ) : null}
                        <h1 className="text-heading-32 text-ink">{name}</h1>
                        <RatingLine reviews={sections['feedback.reviews']} />

                        {available ? null : (
                            <Note variant="warning" label={t('catalog::shop.product.not_available')} data-test="not-available">
                                {t('catalog::shop.product.not_available_text')}
                            </Note>
                        )}

                        {choices.map((choice) => (
                            <fieldset key={choice.id} className="grid gap-2" data-test="choice">
                                <legend className="mb-2 text-label-14 text-ink">{choice.name}</legend>
                                <div className="flex flex-wrap gap-2">
                                    {choice.values.map((value) => {
                                        const becomes = value.onSale ? pick(variants, choices, chosen, choice.id, value.id) : null;
                                        const isChosen = chosen[choice.id] === value.id;

                                        return (
                                            <button
                                                key={value.id}
                                                type="button"
                                                disabled={becomes === null}
                                                aria-pressed={isChosen}
                                                aria-label={becomes === null ? t('catalog::shop.product.unavailable_value', { name: value.name }) : undefined}
                                                onClick={() => becomes !== null && setChosen(becomes.values)}
                                                className={cn(
                                                    'material-small flex items-center gap-2 bg-surface px-3 py-2 text-label-14 text-ink disabled:cursor-not-allowed disabled:text-ink-muted disabled:line-through',
                                                    isChosen && 'shadow-[0_0_0_2px_var(--tw-ink)]',
                                                )}
                                                data-test="choice-value"
                                            >
                                                {value.swatch !== null ? <span className="size-4 rounded-full shadow-[0_0_0_1px_var(--tw-line-strong)]" style={{ backgroundColor: value.swatch }} aria-hidden="true" /> : null}
                                                {value.name}
                                                {/* On the option that is ending soon only (amendment 16(h)). */}
                                                {becomes?.lastPieces ? (
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

                        {choices.length === 0 && variant?.lastPieces ? (
                            <Badge className={cn(tone('amber'), 'w-fit')} data-test="last-pieces">
                                {t('catalog::shop.last_pieces')}
                            </Badge>
                        ) : null}

                        {available && variant !== null && selling !== null ? (
                            <section className="grid gap-1" data-test="how-sold">
                                <h2 className="text-label-14 font-semibold text-ink">{t('catalog::shop.product.how_sold')}</h2>
                                {variant.sellsRetail ? <Terms side={t('catalog::shop.product.retail')} minimum={selling.retailMinimum} maximum={selling.retailMaximum} /> : null}
                                {variant.sellsWholesale && selling.wholesaleMinimum !== null ? (
                                    <Terms side={t('catalog::shop.product.wholesale')} minimum={selling.wholesaleMinimum} maximum={selling.wholesaleMaximum} />
                                ) : null}
                            </section>
                        ) : null}

                        {available ? <SaveHeart wishlist={sections['feedback.wishlist']} productId={page.productId} name={name} /> : null}

                        {variant !== null ? <Details variant={variant} /> : null}
                    </div>
                </div>

                {Object.keys(page.description).length > 0 ? (
                    <section className="grid gap-3">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.description')}</h2>
                        <RichText document={page.description} className="max-w-3xl" />
                    </section>
                ) : null}

                {warranty !== null ? (
                    <section className="grid gap-2" data-test="warranty">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.warranty')}</h2>
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
                        <RichText document={warranty.terms} className="max-w-3xl text-copy-14" />
                    </section>
                ) : null}

                {page.goesWith.length > 0 ? (
                    <section className="grid gap-4" data-test="goes-with">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.goes_with')}</h2>
                        <ProductCards cards={page.goesWith} />
                    </section>
                ) : null}

                {sections['feedback.reviews'] ? <ReviewsSection reviews={sections['feedback.reviews']} url={url} productId={page.productId} /> : null}
                {sections['feedback.questions'] ? <QuestionsSection questions={sections['feedback.questions']} url={url} productId={page.productId} star={sections['feedback.reviews']?.star ?? null} /> : null}

                {page.mayAlsoLike.length > 0 ? (
                    <section className="grid gap-4" data-test="may-also-like">
                        <h2 className="text-heading-20 text-ink">{t('catalog::shop.product.may_also_like')}</h2>
                        <ProductCards cards={page.mayAlsoLike} />
                    </section>
                ) : null}
            </div>
        </StorefrontLayout>
    );
}

/** A large photo and its thumbnails. */
function Gallery({ photos, name }: { photos: PhotoSizes[]; name: string }) {
    const t = useTranslator();
    const [shown, setShown] = useState(0);

    return (
        <div className="grid content-start gap-3" data-test="gallery">
            <ShopPhoto photo={photos[shown] ?? null} size="detail" alt={name} eager className="aspect-square w-full rounded-lg bg-surface" />
            {photos.length > 1 ? (
                <div className="flex flex-wrap gap-2">
                    {photos.map((photo, index) => (
                        <button
                            key={index}
                            type="button"
                            onClick={() => setShown(index)}
                            aria-label={t('catalog::shop.product.photo', { number: index + 1, total: photos.length })}
                            aria-pressed={index === shown}
                            className={cn('overflow-hidden rounded-md', index === shown && 'shadow-[0_0_0_2px_var(--tw-ink)]')}
                        >
                            <ShopPhoto photo={photo} size="thumb" alt="" className="size-16" />
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

    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-2" data-test="details">
            <h2 className="text-label-14 font-semibold text-ink">{t('catalog::shop.product.details')}</h2>
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
