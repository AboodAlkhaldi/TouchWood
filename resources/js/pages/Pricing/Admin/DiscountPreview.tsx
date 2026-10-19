import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import type { DiscountPreviewData, PreviewProductData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { nameIn, OverlapLine, percentOf, plural, ToArrow, useLocale, useT } from './parts';

/*
| What a category discount reaches (pricing.md §4.3.3 P3, Q9) - in its dialog as it is typed, and on its page
| as of today: "Reaches 124 sizes in 38 products", "Up to 31% off", the sizes with no price left out; its
| products 20 at a time with Show More, each opening on its sizes - retail → new price, or Skipped and why;
| what it leaves out; what it overlaps. An empty category is allowed: products added to it later are
| reached. While a new answer is on its way the last one stays, dimmed, a spinner beside the title.
*/

type Props = {
    preview: DiscountPreviewData;
    loading?: boolean;
    zone: string;
    /** The next 20 products after a cursor. */
    more: (after: string) => Promise<DiscountPreviewData | null>;
};

export function DiscountPreview({ preview, loading = false, zone, more }: Props) {
    const t = useT();
    const locale = useLocale();
    const [products, setProducts] = useState<PreviewProductData[]>(preview.reached);
    const [cursor, setCursor] = useState<string | null>(preview.next);
    const [fetching, setFetching] = useState(false);
    // A page of more products may arrive as the page's own preview (a partial visit): it is added, not a new answer.
    const adding = useRef(false);
    // Which answer the list shows: a page asked for an older one is dropped, never added (the review of step 3).
    const generation = useRef(0);

    // A new answer starts the list again.
    useEffect(() => {
        if (adding.current) {
            adding.current = false;

            return;
        }

        generation.current += 1;
        setProducts(preview.reached);
        setCursor(preview.next);
        setFetching(false);
    }, [preview]);

    const overlaps = [...preview.overlappingSales, ...preview.overlappingDiscounts];

    return (
        <section aria-labelledby="discount-preview-title" aria-busy={loading || undefined} className="grid gap-3 rounded-[var(--tw-radius)] bg-surface-sunken p-4" data-test="discount-preview">
            <h3 id="discount-preview-title" className="flex items-center gap-2 text-heading-14 text-ink">
                {t('pricing::admin_discounts.preview.title')}
                {loading ? <Spinner aria-label={t('ui.loading')} className="text-ink-muted" /> : null}
            </h3>
            <div className={loading ? 'grid gap-3 opacity-60' : 'grid gap-3'}>
                {preview.reachedSizes === 0 ? (
                    <p className="text-copy-14 text-ink" data-test="preview-empty">
                        {t('pricing::admin_discounts.preview.empty')}
                    </p>
                ) : (
                    <p className="tw-figure text-copy-14 text-ink" data-test="preview-reaches">
                        {t('pricing::admin_discounts.preview.reaches', {
                            sizes: plural(t, locale, 'pricing::admin_discounts.preview.sizes', preview.reachedSizes),
                            products: plural(t, locale, 'pricing::admin_discounts.products', preview.reachedProducts),
                        })}
                    </p>
                )}
                {preview.deepestBasisPoints !== null ? <p className="tw-figure text-copy-14 text-ink">{t('pricing::admin_discounts.preview.up_to', { percent: percentOf(locale, preview.deepestBasisPoints) })}</p> : null}
                {preview.unpricedSizes > 0 ? (
                    <p className="tw-figure text-copy-13 text-ink-muted" data-test="preview-unpriced">
                        {plural(t, locale, 'pricing::admin_discounts.preview.unpriced', preview.unpricedSizes)}
                    </p>
                ) : null}

                {products.length > 0 ? (
                    <ul className="grid gap-1">
                        {products.map((product) => (
                            <li key={product.productId}>
                                <Collapsible>
                                    <CollapsibleTrigger className="group flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-start text-copy-14 text-ink hover:bg-surface focus-visible:ring-[3px] focus-visible:outline-none">
                                        <span>{nameIn(locale, product.nameAr, product.nameEn)}</span>
                                        <span className="flex items-center gap-2 text-copy-12 text-ink-muted">
                                            <span className="tw-figure">{plural(t, locale, 'pricing::admin_discounts.preview.sizes', product.sizes.length)}</span>
                                            <ChevronDown aria-hidden="true" className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                                        </span>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <ul className="grid gap-1 ps-4 pt-1 pb-2">
                                            {product.sizes.map((size) => (
                                                <li key={size.variantId} className="flex flex-wrap items-center gap-x-2 text-copy-13" data-test={`preview-size-${size.variantId}`}>
                                                    <span className="text-ink">{size.name}</span>
                                                    <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                        {size.code}
                                                    </span>
                                                    <Money value={size.retail} className="text-ink-muted" />
                                                    {size.skipped === null && size.newPrice !== null ? (
                                                        <>
                                                            <ToArrow /> <Money value={size.newPrice} />
                                                        </>
                                                    ) : (
                                                        <span className="text-warn">
                                                            {t('pricing::admin_discounts.preview.skipped')}: {size.skipped === null ? '' : t(`pricing::admin_discounts.preview.why.${size.skipped}`)}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </CollapsibleContent>
                                </Collapsible>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {cursor !== null ? (
                    <LoadMoreButton
                        loading={fetching}
                        onClick={() => {
                            setFetching(true);
                            adding.current = true;
                            const asked = generation.current;
                            more(cursor).then((page) => {
                                adding.current = false;

                                if (asked !== generation.current) {
                                    return;
                                }

                                setFetching(false);

                                if (page !== null) {
                                    setProducts((before) => [...before, ...page.reached.filter((each) => !before.some((had) => had.productId === each.productId))]);
                                    setCursor(page.next);
                                }
                            });
                        }}
                    />
                ) : null}

                {preview.leftOut.length > 0 ? (
                    <p className="text-copy-13 text-ink-muted">
                        {t('pricing::admin_discounts.preview.left_out')}: {preview.leftOut.map((product) => nameIn(locale, product.nameAr, product.nameEn)).join(t('pricing::admin.list_separator'))}
                    </p>
                ) : null}
                {overlaps.length > 0 ? (
                    <div className="grid gap-1" data-test="preview-overlaps">
                        <p className="text-label-13 text-ink">{t('pricing::admin_discounts.preview.overlaps')}</p>
                        <ul className="grid gap-1 text-copy-13 text-ink-muted">
                            {overlaps.map((other) => (
                                <li key={other.id}>
                                    <OverlapLine description={other.description} startsAt={other.startsAt} endsAt={other.endsAt} alwaysWins={other.alwaysWins} zone={zone} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </section>
    );
}
