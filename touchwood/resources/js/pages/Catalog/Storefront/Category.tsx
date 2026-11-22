import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDownUp, Check, SlidersHorizontal, X } from 'lucide-react';
import { ListingSlots, type ListingSlotsData } from '@/components/content/ListingSlot';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards, ProductRows, useCardFacts, useShopView, ViewSwitch } from '@/components/shop/ProductCards';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { figure } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopCardData, ShopCategoryPage, ShopFilterChoiceData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| A category's page in the shop (catalog.md §4.5; shop-design.md §5): the path to it, its 34 px name, its description and
| «Showing 1–24 of 918», its sub-categories as links, then everything listed in it or below it - 24 at a time with Show
| More (keyset), as a grid or the technical list (C7, kept per browser, P7). The filters (P14) in a 248 px sticky column:
| their title and a copper Clear All, the chosen ones as chips, then how it is sold (a company's or a guest's), the brands
| and the filter attributes' values, each with how many products it would show - all in the address. The sort (P15) in a
| 38 px box: Best-Selling, the default, Newest, Best Rated where the store shows ratings, and the price both ways (C8).
| On a phone: the compact top bar (C12), a sticky Sort and Filter bar, each opening a bottom sheet. Emptied only by stock
| or prices, a sentence saying so (amendment 39).
*/

type Chosen = { brands: string[]; values: string[]; side: string | null; sort: string };

const DEFAULT_SORT = 'best-selling';

/** The address's query for these choices: lists comma-separated, the defaults left out. */
function query(chosen: Chosen): Record<string, string> {
    const entries: [string, string | null][] = [
        ['brands', chosen.brands.length > 0 ? chosen.brands.join(',') : null],
        ['values', chosen.values.length > 0 ? chosen.values.join(',') : null],
        ['side', chosen.side],
        ['sort', chosen.sort !== DEFAULT_SORT ? chosen.sort : null],
    ];

    return Object.fromEntries(entries.filter((entry): entry is [string, string] => entry[1] !== null));
}

export default function Category({ name, slug, path, children, products, next, filters, address, cardFacts, soldOut, slots, description, total }: ShopCategoryPage & { slots?: ListingSlotsData }) {
    const t = useTranslator();
    const link = useLink();
    const { locale } = usePage<SharedProps>().props;
    const here = link('storefront.catalog.category', { slug });
    const facts = useCardFacts(cardFacts, products[0]?.productId);
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId, facts);
    const [view, setView] = useShopView();
    const [sheet, setSheet] = useState<'filter' | 'sort' | null>(null);
    const picked = (choices: ShopFilterChoiceData[]) => choices.filter((choice) => choice.chosen).map((choice) => choice.id);
    const chosen: Chosen = {
        brands: picked(filters.brands),
        values: filters.attributes.flatMap((group) => picked(group.choices)),
        side: picked(filters.sides)[0] ?? null,
        sort: filters.sort,
    };
    const chips = [
        ...filters.sides.filter((choice) => choice.chosen).map((choice) => ({ key: `side:${choice.id}`, name: t(`catalog::shop.filters.${choice.id}`), drop: { ...chosen, side: null } })),
        ...filters.brands.filter((choice) => choice.chosen).map((choice) => ({ key: `brand:${choice.id}`, name: choice.name, drop: { ...chosen, brands: chosen.brands.filter((id) => id !== choice.id) } })),
        ...filters.attributes.flatMap((group) =>
            group.choices.filter((choice) => choice.chosen).map((choice) => ({ key: `value:${choice.id}`, name: choice.name, drop: { ...chosen, values: chosen.values.filter((id) => id !== choice.id) } })),
        ),
    ];
    const sorts = ['best-selling', 'newest', ...(filters.ratingSort ? ['rating'] : []), 'price-low', 'price-high'];
    // The way up: the category above it, or the shop's home.
    const above = path.at(-1);
    const parent = above ? link('storefront.catalog.category', { slug: above.slug }) : link('storefront.home');

    function visit(next: Chosen) {
        // Picked in the phone's sheet, the sheet stays open for the next choice; the list still takes what the server sends.
        router.get(here, query(next), { preserveScroll: true, preserveState: sheet === 'filter' });
    }

    function toggle(list: string[], id: string): string[] {
        return list.includes(id) ? list.filter((item) => item !== id) : [...list, id];
    }

    const panel = (
        <div className="grid content-start gap-6" data-test="filters">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-label-16 font-semibold text-ink">{t('catalog::shop.filters.title')}</h2>
                {chips.length > 0 ? (
                    <Button variant="link" className="h-auto p-0 text-accent" onClick={() => visit({ brands: [], values: [], side: null, sort: chosen.sort })} data-test="clear-all">
                        {t('catalog::shop.filters.clear_all')}
                    </Button>
                ) : null}
            </div>
            {chips.length > 0 ? (
                <div className="flex flex-wrap gap-2" data-test="chips">
                    {chips.map((chip) => (
                        <Button key={chip.key} variant="secondary" size="sm" className="h-7 rounded-full" onClick={() => visit(chip.drop)} aria-label={t('catalog::shop.filters.remove', { name: chip.name })} data-test="chip">
                            {chip.name}
                            <X aria-hidden="true" />
                        </Button>
                    ))}
                </div>
            ) : null}
            {/* How it is sold, the brands, then the values (shop-design.md §5.2). */}
            {filters.sides.length > 0 ? (
                <FilterGroup
                    title={t('catalog::shop.filters.sides')}
                    choices={filters.sides.map((choice) => ({ ...choice, name: t(`catalog::shop.filters.${choice.id}`) }))}
                    test="filter-side"
                    onToggle={(id) => visit({ ...chosen, side: chosen.side === id ? null : id })}
                />
            ) : null}
            {filters.brands.length > 0 ? (
                <FilterGroup title={t('catalog::shop.filters.brands')} choices={filters.brands} test="filter-brand" onToggle={(id) => visit({ ...chosen, brands: toggle(chosen.brands, id) })} />
            ) : null}
            {filters.attributes.map((group) => (
                <FilterGroup key={group.id} title={group.name} choices={group.choices} test="filter-value" onToggle={(id) => visit({ ...chosen, values: toggle(chosen.values, id) })} />
            ))}
        </div>
    );

    return (
        <StorefrontLayout title={name} address={address} compact={{ back: parent }}>
            <div className="grid gap-5 sm:gap-6">
                <div className="max-sm:hidden">
                    <ShopTrail path={path} current={name} />
                </div>
                <header className="grid gap-2 border-b border-line pb-5" data-test="category-head">
                    <h1 className="text-[26px] leading-tight font-semibold text-ink sm:text-[34px]">{name}</h1>
                    {description ? (
                        <p className="max-w-3xl text-copy-14 text-ink-muted sm:text-copy-16" data-test="category-description">
                            {description}
                        </p>
                    ) : null}
                    {total > 0 && list.rows.length > 0 ? (
                        <p className="tw-figure text-copy-13 text-ink-muted" data-test="category-showing">
                            {t('catalog::shop.showing', { shown: figure(locale, list.rows.length), total: figure(locale, total) })}
                        </p>
                    ) : null}
                </header>

                {children.length > 0 ? (
                    <nav aria-label={t('catalog::shop.sub_categories')} className="flex flex-wrap gap-2" data-test="sub-categories">
                        {children.map((child) => (
                            <Button key={child.slug} variant="outline" size="sm" className="rounded-full" asChild>
                                <Link href={link('storefront.catalog.category', { slug: child.slug })}>{child.name}</Link>
                            </Button>
                        ))}
                    </nav>
                ) : null}

                {/* The phone's sticky Sort and Filter bar, under the compact top bar (shop-design.md §5.4). */}
                <div className="sticky top-[53px] z-30 -mx-4 grid grid-cols-2 border-y border-line bg-surface sm:top-[79px] sm:-mx-6 md:hidden" data-test="phone-bar">
                    <Button variant="ghost" className="h-11 rounded-none border-e border-line" onClick={() => setSheet('sort')} data-test="phone-sort">
                        <ArrowDownUp aria-hidden="true" />
                        {t('catalog::shop.sort.label')}
                    </Button>
                    <Button variant="ghost" className="h-11 rounded-none" onClick={() => setSheet('filter')} data-test="phone-filter">
                        <SlidersHorizontal aria-hidden="true" />
                        {t('catalog::shop.filters.filter')}
                        {chips.length > 0 ? <span className="tw-figure rounded-full bg-brand px-1.5 text-label-12 text-ink-on-brand">{figure(locale, chips.length)}</span> : null}
                    </Button>
                </div>

                <div className="grid gap-6 md:grid-cols-[248px_1fr] md:gap-[34px]">
                    <aside className="hidden md:sticky md:top-36 md:block md:max-h-[calc(100vh-10rem)] md:self-start md:overflow-y-auto" aria-label={t('catalog::shop.filters.title')}>
                        {panel}
                    </aside>

                    <section className="grid min-w-0 content-start gap-4" aria-label={t('catalog::shop.products')}>
                        <div className="hidden items-center justify-end gap-3 md:flex">
                            <label htmlFor="shop-sort" className="text-copy-14 text-ink-muted">
                                {t('catalog::shop.sort.by')}
                            </label>
                            <NativeSelect id="shop-sort" className="h-[38px] w-56" value={chosen.sort} onChange={(event) => visit({ ...chosen, sort: event.target.value })} data-test="sort">
                                {sorts.map((sort) => (
                                    <NativeSelectOption key={sort} value={sort}>
                                        {t(`catalog::shop.sort.${sort}`)}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                            <ViewSwitch view={view} onChange={setView} />
                        </div>

                        <ListingSlots slots={slots} />

                        {list.rows.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t(soldOut ? 'catalog::shop.nothing_now' : chips.length > 0 ? 'catalog::shop.filters.none' : 'catalog::shop.empty')}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : view === 'list' ? (
                            <>
                                <div className="hidden md:block">
                                    <ProductRows cards={list.rows} />
                                </div>
                                <div className="md:hidden">
                                    <ProductCards facts={facts} cards={list.rows} />
                                </div>
                            </>
                        ) : (
                            <ProductCards facts={facts} cards={list.rows} />
                        )}

                        {next !== null ? <LoadMoreButton shop loading={list.loading} onClick={() => list.more(here, { ...query(chosen), after: next }, ['products', 'next', 'cardFacts'])} /> : null}
                    </section>
                </div>
            </div>

            {/* The phone's bottom sheets (shop-design.md §5.4); a tablet up never opens them. */}
            <Sheet open={sheet === 'filter'} onOpenChange={(open) => setSheet(open ? 'filter' : null)}>
                <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto rounded-t-2xl px-4 pb-6" data-test="filter-sheet">
                    <SheetHeader className="px-0">
                        <SheetTitle className="sr-only">{t('catalog::shop.filters.title')}</SheetTitle>
                        <SheetDescription className="sr-only">{name}</SheetDescription>
                    </SheetHeader>
                    {panel}
                </SheetContent>
            </Sheet>
            <Sheet open={sheet === 'sort'} onOpenChange={(open) => setSheet(open ? 'sort' : null)}>
                <SheetContent side="bottom" className="rounded-t-2xl px-4 pb-6" data-test="sort-sheet">
                    <SheetHeader className="px-0">
                        <SheetTitle>{t('catalog::shop.sort.label')}</SheetTitle>
                        <SheetDescription className="sr-only">{name}</SheetDescription>
                    </SheetHeader>
                    <div className="grid" role="radiogroup" aria-label={t('catalog::shop.sort.label')}>
                        {sorts.map((sort) => (
                            <button
                                key={sort}
                                type="button"
                                role="radio"
                                aria-checked={chosen.sort === sort}
                                className="flex h-12 items-center justify-between border-b border-line text-start text-copy-16 text-ink last:border-b-0"
                                onClick={() => {
                                    setSheet(null);

                                    if (chosen.sort !== sort) {
                                        visit({ ...chosen, sort });
                                    }
                                }}
                                data-test="sort-option"
                            >
                                {t(`catalog::shop.sort.${sort}`)}
                                {chosen.sort === sort ? <Check aria-hidden="true" className="size-4 text-brand" /> : null}
                            </button>
                        ))}
                    </div>
                </SheetContent>
            </Sheet>
        </StorefrontLayout>
    );
}

/** One kind of filter: its choices as checkboxes with how many each would show; none that would show nothing, unless it is picked. */
function FilterGroup({ title, choices, test, onToggle }: { title: string; choices: ShopFilterChoiceData[]; test: string; onToggle: (id: string) => void }) {
    return (
        <fieldset className="grid gap-2.5 border-t border-line pt-4">
            <legend className="float-start mb-2 w-full text-label-14 font-semibold text-ink">{title}</legend>
            {choices.map((choice) => (
                <label key={choice.id} className="flex items-center gap-2 text-copy-14 text-ink has-[button:disabled]:text-ink-muted" data-test={test}>
                    <Checkbox checked={choice.chosen} disabled={!choice.chosen && choice.count === 0} onCheckedChange={() => onToggle(choice.id)} />
                    {choice.swatch !== null ? <span className="size-4 rounded-full shadow-[0_0_0_1px_var(--tw-line-strong)]" style={{ backgroundColor: choice.swatch }} aria-hidden="true" /> : null}
                    <span className="flex-1">{choice.name}</span>
                    <span className="tw-figure text-label-12 text-ink-muted">{choice.count}</span>
                </label>
            ))}
        </fieldset>
    );
}
