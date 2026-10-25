import { Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { ProductCards, useCardFacts } from '@/components/shop/ProductCards';
import { ShopTrail } from '@/components/shop/ShopTrail';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { StorefrontLayout } from '@/layouts/StorefrontLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ShopCardData, ShopCategoryPage, ShopFilterChoiceData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| A category's page in the shop (catalog.md §4.5): the path to it, its name, its sub-categories as
| links, then everything listed in it or below it - 24 at a time with Show More (keyset). The filters
| (P14): the brands, the filter attributes' values present, and Retail or Wholesale for a company or a
| guest, each with how many products it would show; the chosen ones as chips with Clear All, all in
| the address. The sort (P15): Best-Selling, the default - newest until Sales ranks products - and
| Newest. No price, no stock, no Add to Cart.
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

export default function Category({ name, slug, path, children, products, next, filters, address, cardFacts }: ShopCategoryPage) {
    const t = useTranslator();
    const link = useLink();
    const here = link('storefront.catalog.category', { slug });
    const list = useLoadMore<ShopCardData>(products, (card) => card.productId);
    const facts = useCardFacts(cardFacts);
    const picked = (choices: ShopFilterChoiceData[]) => choices.filter((choice) => choice.chosen).map((choice) => choice.id);
    const chosen: Chosen = {
        brands: picked(filters.brands),
        values: filters.attributes.flatMap((group) => picked(group.choices)),
        side: picked(filters.sides)[0] ?? null,
        sort: filters.sort,
    };
    const chips = [
        ...filters.brands.filter((choice) => choice.chosen).map((choice) => ({ key: `brand:${choice.id}`, name: choice.name, drop: { ...chosen, brands: chosen.brands.filter((id) => id !== choice.id) } })),
        ...filters.attributes.flatMap((group) =>
            group.choices.filter((choice) => choice.chosen).map((choice) => ({ key: `value:${choice.id}`, name: choice.name, drop: { ...chosen, values: chosen.values.filter((id) => id !== choice.id) } })),
        ),
        ...filters.sides.filter((choice) => choice.chosen).map((choice) => ({ key: `side:${choice.id}`, name: t(`catalog::shop.filters.${choice.id}`), drop: { ...chosen, side: null } })),
    ];

    function visit(next: Chosen) {
        router.get(here, query(next), { preserveScroll: true });
    }

    function toggle(list: string[], id: string): string[] {
        return list.includes(id) ? list.filter((item) => item !== id) : [...list, id];
    }

    const panel = (
        <div className="grid content-start gap-6" data-test="filters">
            {filters.brands.length > 0 ? (
                <FilterGroup title={t('catalog::shop.filters.brands')} choices={filters.brands} test="filter-brand" onToggle={(id) => visit({ ...chosen, brands: toggle(chosen.brands, id) })} />
            ) : null}
            {filters.attributes.map((group) => (
                <FilterGroup key={group.id} title={group.name} choices={group.choices} test="filter-value" onToggle={(id) => visit({ ...chosen, values: toggle(chosen.values, id) })} />
            ))}
            {filters.sides.length > 0 ? (
                <FilterGroup
                    title={t('catalog::shop.filters.sides')}
                    choices={filters.sides.map((choice) => ({ ...choice, name: t(`catalog::shop.filters.${choice.id}`) }))}
                    test="filter-side"
                    onToggle={(id) => visit({ ...chosen, side: chosen.side === id ? null : id })}
                />
            ) : null}
        </div>
    );

    return (
        <StorefrontLayout title={name} address={address}>
            <div className="grid gap-6">
                <ShopTrail path={path} current={name} />
                <h1 className="text-heading-32 text-ink">{name}</h1>

                {children.length > 0 ? (
                    <nav aria-label={t('catalog::shop.sub_categories')} className="flex flex-wrap gap-2" data-test="sub-categories">
                        {children.map((child) => (
                            <Button key={child.slug} variant="outline" size="sm" asChild>
                                <Link href={link('storefront.catalog.category', { slug: child.slug })}>{child.name}</Link>
                            </Button>
                        ))}
                    </nav>
                ) : null}

                <div className="grid gap-6 md:grid-cols-[14rem_1fr]">
                    <aside className="hidden md:block" aria-label={t('catalog::shop.filters.title')}>
                        {panel}
                    </aside>
                    <Collapsible className="md:hidden">
                        <CollapsibleTrigger asChild>
                            <Button variant="outline" size="sm">
                                {t('catalog::shop.filters.open')}
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="pt-4">{panel}</CollapsibleContent>
                    </Collapsible>

                    <section className="grid content-start gap-4" aria-label={t('catalog::shop.products')}>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2" data-test="chips">
                                {chips.map((chip) => (
                                    <Button key={chip.key} variant="secondary" size="sm" onClick={() => visit(chip.drop)} aria-label={t('catalog::shop.filters.remove', { name: chip.name })} data-test="chip">
                                        {chip.name}
                                        <X aria-hidden="true" />
                                    </Button>
                                ))}
                                {chips.length > 0 ? (
                                    <Button variant="ghost" size="sm" onClick={() => visit({ brands: [], values: [], side: null, sort: chosen.sort })} data-test="clear-all">
                                        {t('catalog::shop.filters.clear_all')}
                                    </Button>
                                ) : null}
                            </div>
                            <NativeSelect
                                id="shop-sort"
                                size="sm"
                                className="w-44"
                                value={chosen.sort}
                                aria-label={t('catalog::shop.sort.label')}
                                onChange={(event) => visit({ ...chosen, sort: event.target.value })}
                                data-test="sort"
                            >
                                {['best-selling', 'newest', ...(filters.ratingSort ? ['rating'] : [])].map((sort) => (
                                    <NativeSelectOption key={sort} value={sort}>
                                        {t(`catalog::shop.sort.${sort}`)}
                                    </NativeSelectOption>
                                ))}
                            </NativeSelect>
                        </div>

                        {list.rows.length === 0 ? (
                            <Empty className="py-12">
                                <EmptyHeader>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t(chips.length > 0 ? 'catalog::shop.filters.none' : 'catalog::shop.empty')}</EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <ProductCards facts={facts} cards={list.rows} />
                        )}

                        {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(here, { ...query(chosen), after: next }, ['products', 'next', 'cardFacts'])} /> : null}
                    </section>
                </div>
            </div>
        </StorefrontLayout>
    );
}

/** One kind of filter: its choices as checkboxes with how many each would show; none that would show nothing, unless it is picked. */
function FilterGroup({ title, choices, test, onToggle }: { title: string; choices: ShopFilterChoiceData[]; test: string; onToggle: (id: string) => void }) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-label-14 font-semibold text-ink">{title}</legend>
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
