import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import type { ChoiceData } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| The coupon rules' picker (promotions.md §4.1 M2; owner's Q4, Q5): one dialog with three tabs - a
| product found by name or code (the server searches, 20 at a time), a category read by its name and its
| path, a brand by its name. Staff read and pick names; an id never shows. What is already on the list
| is marked and cannot be picked twice.
*/

export type Target = 'product' | 'category' | 'brand';

/** What was picked: a category with its whole path, root first, as its chip names it (owner's Q4). */
export type Picked = { target: Target; id: string; name: string; path: string | null };

export function RulePicker({
    open,
    onOpenChange,
    title,
    categories,
    brands,
    products,
    taken,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    categories: ChoiceData[];
    brands: ChoiceData[];
    products: ChoiceData[];
    /** `{target}:{id}` of every entry already on this list. */
    taken: Set<string>;
    onPick: (picked: Picked) => void;
}) {
    const t = useTranslator();
    const [tab, setTab] = useState<Target>('category');
    const [filter, setFilter] = useState('');
    const [search, setSearch] = useState('');
    const [searching, setSearching] = useState(false);
    const first = useRef(true);

    // The product search runs on the server once typing stops; only the found products come back.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const typed = search.trim();
        const timer = window.setTimeout(() => {
            const url = new URL(window.location.href);

            if (typed.length >= 2) {
                url.searchParams.set('product_search', typed);
            } else {
                url.searchParams.delete('product_search');
            }

            router.get(url.pathname, Object.fromEntries(url.searchParams), {
                only: ['products'],
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    const matches = (choice: ChoiceData) => {
        const words = filter.trim().toLowerCase();

        return words === '' || `${choice.name} ${choice.detail ?? ''}`.toLowerCase().includes(words);
    };

    const list = (target: Target, choices: ChoiceData[], empty: string) => (
        <div role="list" className="grid max-h-80 gap-1 overflow-y-auto" data-test={`choices-${target}`}>
            {/* A status, so a screen reader hears "Searching…" and what the search found. */}
            {choices.length === 0 ? (
                <p role="status" className="py-6 text-center text-copy-14 text-ink-muted">
                    {empty}
                </p>
            ) : null}
            {choices.map((choice) => {
                const already = taken.has(`${target}:${choice.id}`);

                return (
                    <div key={choice.id} role="listitem" className="flex items-center justify-between gap-3 rounded-[var(--tw-radius)] px-2 py-1.5 hover:bg-surface-sunken">
                        <span className="min-w-0">
                            <span className="block truncate text-label-14 text-ink">{choice.name}</span>
                            {choice.detail === null ? null : <span className="block truncate text-copy-13 text-ink-muted">{choice.detail}</span>}
                        </span>
                        {/* Out of reach once added, yet still focusable: the keyboard keeps its place in the list. */}
                        <ActionButton
                            type="button"
                            size="sm"
                            variant="outline"
                            disabledReason={already ? t('promotions::admin_coupons.picker.already') : undefined}
                            aria-label={t(already ? 'promotions::admin_coupons.picker.added_named' : 'promotions::admin_coupons.picker.add_named', { name: choice.name })}
                            onClick={() => onPick({ target, id: choice.id, name: choice.name, path: target === 'category' ? (choice.detail === null ? choice.name : `${choice.detail} › ${choice.name}`) : null })}
                            data-test={`pick-${target}-${choice.id}`}
                        >
                            {already ? t('promotions::admin_coupons.picker.added') : t('promotions::admin_coupons.picker.add')}
                        </ActionButton>
                    </div>
                );
            })}
        </div>
    );

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            wide
            title={title}
            description={t('promotions::admin_coupons.picker.description')}
            busy={false}
            confirm={
                <Button type="button" onClick={() => onOpenChange(false)} data-test="picker-done">
                    {t('promotions::admin_coupons.picker.done')}
                </Button>
            }
        >
            <Tabs value={tab} onValueChange={(next) => (next === 'product' || next === 'category' || next === 'brand' ? setTab(next) : undefined)}>
                <TabsList aria-label={t('promotions::admin_coupons.picker.tabs_label')}>
                    <TabsTrigger value="category" data-test="picker-category">
                        {t('promotions::admin_coupons.target.category')}
                    </TabsTrigger>
                    <TabsTrigger value="brand" data-test="picker-brand">
                        {t('promotions::admin_coupons.target.brand')}
                    </TabsTrigger>
                    <TabsTrigger value="product" data-test="picker-product">
                        {t('promotions::admin_coupons.target.product')}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="category" className="grid gap-3 pt-3">
                    <SearchField id="filter-category" label={t('promotions::admin_coupons.picker.filter_category')} placeholder={t('promotions::admin_coupons.picker.filter_category')} value={filter} onValueChange={setFilter} />
                    {list('category', categories.filter(matches), t('promotions::admin_coupons.picker.none'))}
                </TabsContent>
                <TabsContent value="brand" className="grid gap-3 pt-3">
                    <SearchField id="filter-brand" label={t('promotions::admin_coupons.picker.filter_brand')} placeholder={t('promotions::admin_coupons.picker.filter_brand')} value={filter} onValueChange={setFilter} />
                    {list('brand', brands.filter(matches), t('promotions::admin_coupons.picker.none'))}
                </TabsContent>
                <TabsContent value="product" className="grid gap-3 pt-3">
                    <SearchField id="search-product" label={t('promotions::admin_coupons.picker.search_product')} placeholder={t('promotions::admin_coupons.picker.search_product')} value={search} onValueChange={setSearch} />
                    {search.trim().length < 2 ? (
                        <p className="text-copy-13 text-ink-muted">{t('promotions::admin_coupons.picker.type_two')}</p>
                    ) : (
                        list('product', searching ? [] : products, searching ? t('promotions::admin_coupons.picker.searching') : t('promotions::admin_coupons.picker.none'))
                    )}
                </TabsContent>
            </Tabs>
        </PanelDialog>
    );
}
