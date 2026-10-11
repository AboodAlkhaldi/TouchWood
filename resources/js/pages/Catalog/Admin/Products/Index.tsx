import { useEffect, useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchCombobox } from '@/components/SearchCombobox';
import { StoreFilter } from '@/components/StoreFilter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { useLoadMore } from '@/lib/use-load-more';
import type { BrandOptionData, ProductRowData, ProductsPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { StageBadge } from './parts';

/*
| The products list (catalog.md §4.4 S8, P4): every product, newest first, 50 at a time with Show More
| - never a price, stock or tax (stage 5). The store filter offers All Stores first; one store chosen,
| the stores column becomes that store's state, and a filter by it. **The whole row opens the
| product.** Add Product asks no store (amendment 13(f)): a draft, and its page opens.
*/

const STAGES = ['DRAFT', 'READY', 'ARCHIVED'] as const;
const STATES = ['ON', 'OFF', 'NOT_CHOSEN', 'NOT_AVAILABLE'] as const;

type Filters = { q: string; stage: string; category: string; brand: string; state: string };

export default function Index(page: ProductsPage) {
    const { products, more, after, storeCode, stores, mayCreate, brands, categories } = page;
    const t = useTranslator();
    const locale = useLocale();
    const applied: Filters = { q: page.search ?? '', stage: page.stage ?? '', category: page.categoryId ?? '', brand: page.brandId ?? '', state: page.storeState ?? '' };
    const [form, setForm] = useState<Filters>(applied);
    const [adding, setAdding] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<ProductRowData>(products, (row) => row.id);
    const filtered = Object.values(applied).some((value) => value !== '');
    // Products stay in a brand or category switched off, so the filters offer every one, saying which is off.
    const off = (name: string, active: boolean) => (active ? name : `${name} (${t('catalog::admin.state.inactive')})`);

    // The address the page shows, as asked: the store kept, empty filters left out.
    const asked = (filters: Filters) => {
        const params: Record<string, string> = {};

        for (const [key, value] of Object.entries({ ...filters, store: storeCode ?? '' })) {
            if (value !== '' && !(key === 'state' && storeCode === null)) {
                params[key] = value;
            }
        }

        return params;
    };

    const apply = (filters: Filters) => router.get('/admin/products', asked(filters), { preserveState: true });

    return (
        <AdminLayout
            title={t('catalog::admin_products.title')}
            subtitle={t('catalog::admin_products.subtitle')}
            action={
                <ActionButton
                    type="button"
                    disabledReason={mayCreate ? undefined : t('catalog::admin_products.add_reason')}
                    onClick={(event) => {
                        opener.current = event.currentTarget;
                        setAdding(true);
                    }}
                    data-test="add-product"
                >
                    {t('catalog::admin_products.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <StoreFilter stores={stores} value={storeCode} all className="max-w-xs" />

                <Card className="material-base gap-0 border-0 py-0">
                    <form
                        aria-labelledby="filters-title"
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply(form);
                        }}
                    >
                        <CardHeader className="px-5 pt-4 pb-3">
                            <CardTitle>
                                <h2 id="filters-title" className="text-heading-14 text-ink">
                                    {t('catalog::admin_products.filters.title')}
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 px-5 pb-4 sm:grid-cols-2 lg:grid-cols-4">
                            <TextField
                                id="product-search"
                                type="search"
                                label={t('catalog::admin_products.filters.search')}
                                placeholder={t('catalog::admin_products.filters.search_placeholder')}
                                value={form.q}
                                onChange={(event) => setForm({ ...form, q: event.target.value })}
                                data-test="product-search"
                            />
                            <SelectField id="product-stage" label={t('catalog::admin_products.filters.stage')} value={form.stage} onChange={(event) => setForm({ ...form, stage: event.target.value })} data-test="product-stage">
                                <NativeSelectOption value="">{t('catalog::admin_products.filters.any')}</NativeSelectOption>
                                {STAGES.map((stage) => (
                                    <NativeSelectOption key={stage} value={stage}>
                                        {t(`catalog::admin.stage.${stage}`)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                            <SearchCombobox
                                id="product-category"
                                label={t('catalog::admin_products.filters.category')}
                                options={[{ value: '', label: t('catalog::admin_products.filters.any') }, ...categories.map((category) => ({ value: category.id, label: off(locale === 'ar' ? category.pathAr : category.pathEn, category.active) }))]}
                                value={form.category}
                                onChange={(category) => setForm({ ...form, category })}
                                words={{ search: t('catalog::admin_products.field.category_search'), none: (query) => t('catalog::admin_products.field.category_none', { query }) }}
                            />
                            <SelectField id="product-brand" label={t('catalog::admin_products.filters.brand')} value={form.brand} onChange={(event) => setForm({ ...form, brand: event.target.value })}>
                                <NativeSelectOption value="">{t('catalog::admin_products.filters.any')}</NativeSelectOption>
                                {brands.map((brand) => (
                                    <NativeSelectOption key={brand.id} value={brand.id}>
                                        {off(nameIn(locale, brand.nameAr, brand.nameEn), brand.active)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>
                            {storeCode !== null ? (
                                <SelectField id="product-state" label={t('catalog::admin_products.filters.state')} value={form.state} onChange={(event) => setForm({ ...form, state: event.target.value })} data-test="product-state">
                                    <NativeSelectOption value="">{t('catalog::admin_products.filters.any')}</NativeSelectOption>
                                    {STATES.map((state) => (
                                        <NativeSelectOption key={state} value={state}>
                                            {t(`catalog::admin_products.state_filter.${state}`)}
                                        </NativeSelectOption>
                                    ))}
                                </SelectField>
                            ) : null}
                        </CardContent>
                        <div className="flex flex-wrap gap-2 px-5 pb-4">
                            <Button type="submit" data-test="apply-filters">
                                {t('catalog::admin_products.filters.apply')}
                            </Button>
                            {filtered ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        const none = { q: '', stage: '', category: '', brand: '', state: '' };
                                        setForm(none);
                                        apply(none);
                                    }}
                                >
                                    {t('catalog::admin_products.filters.clear')}
                                </Button>
                            ) : null}
                        </div>
                    </form>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="products-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(filtered ? 'catalog::admin_products.empty.filtered_title' : 'catalog::admin_products.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(filtered ? 'catalog::admin_products.empty.filtered_body' : 'catalog::admin_products.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('catalog::admin_products.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead className="w-14">
                                        <span className="sr-only">{t('catalog::admin_products.column.photo')}</span>
                                    </TableHead>
                                    <TableHead>{t('catalog::admin_products.column.name')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.column.codes')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.column.brand')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.column.category')}</TableHead>
                                    <TableHead>{t('catalog::admin_products.column.stage')}</TableHead>
                                    <TableHead>{storeCode === null ? t('catalog::admin_products.column.in_stores') : t('catalog::admin_products.column.in_store')}</TableHead>
                                    <TableHead className="text-end">{t('catalog::admin_products.column.variants')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((row) => (
                                    <Row key={row.id} row={row} storeChosen={storeCode !== null} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {more && after !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/products', { ...asked(applied), after }, ['products', 'more', 'after'])} /> : null}
            </div>

            {adding ? <AddProductDialog brands={brands.filter((brand) => brand.active)} open onOpenChange={(open) => (open ? undefined : setAdding(false))} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Row({ row, storeChosen }: { row: ProductRowData; storeChosen: boolean }) {
    const t = useTranslator();
    const locale = useLocale();
    const name = nameIn(locale, row.nameAr, row.nameEn);
    const other = locale === 'ar' ? row.nameEn : row.nameAr;

    return (
        <TableRow className="relative hover:bg-surface-sunken" data-test={`product-${row.id}`}>
            <TableCell>{row.photo !== null ? <img src={row.photo} alt="" className="size-10 rounded-md border border-line object-cover" /> : <span className="block size-10 rounded-md bg-surface-sunken" aria-hidden="true" />}</TableCell>
            <TableCell>
                <span className="grid">
                    {/* The whole row opens the product: the name's link stretches over it. */}
                    <Link href={`/admin/products/${row.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]">
                        {name}
                    </Link>
                    {other ? (
                        <span className="text-copy-12 text-ink-muted" dir={locale === 'ar' ? 'ltr' : 'rtl'}>
                            {other}
                        </span>
                    ) : null}
                </span>
            </TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {row.codes.join(' · ')}
            </TableCell>
            <TableCell>{nameIn(locale, row.brandNameAr, row.brandNameEn)}</TableCell>
            <TableCell>{row.categoryNameAr === null ? '—' : nameIn(locale, row.categoryNameAr, row.categoryNameEn)}</TableCell>
            <TableCell>
                <StageBadge stage={row.stage} />
            </TableCell>
            <TableCell data-test="product-stores">
                {storeChosen ? (
                    t(`catalog::admin_products.state.${row.storeState ?? 'NOT_CHOSEN'}`, { on: figure(locale, row.storeVariantsOn), all: figure(locale, row.variants) })
                ) : row.onIn.length === 0 ? (
                    '—'
                ) : (
                    <span className="flex flex-wrap gap-1">
                        {row.onIn.map((code) => (
                            <Badge key={code} variant="outline" className="uppercase" dir="ltr">
                                {code}
                            </Badge>
                        ))}
                    </span>
                )}
            </TableCell>
            <TableCell className="tw-figure text-end">{figure(locale, row.variants)}</TableCell>
        </TableRow>
    );
}

/** A new draft: the Arabic name, the English one if known, its brand - the default chosen (S8). */
function AddProductDialog({ brands, open, onOpenChange, returnFocusTo }: { brands: BrandOptionData[]; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = () => ({ name_ar: '', name_en: '', brand_id: brands.find((brand) => brand.isDefault)?.id ?? '' });
    const form = useForm(initial());
    // Each name as typed (frontend.md §1.7), with the domain's rule (ProductName): one line of at most
    // 200 characters, the Arabic one required; a draft may wait for its English name. Afresh each
    // time the dialog opens.
    const checks = useChecks(
        [
            { id: 'product-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 200 } } },
            { id: 'product-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: { length: { max: 200 } } },
        ],
        open,
    );

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open]);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('catalog::admin_products.add')}
            description={t('catalog::admin_products.add_body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={() => checks.submit(() => form.post('/admin/products', { preserveScroll: true }))} data-test="confirm-product">
                    {t('catalog::admin_products.add')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="product-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('product-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} />
                <TextField
                    id="product-name-en"
                    dir="ltr"
                    label={t('catalog::admin.field.name_en')}
                    helper={t('catalog::admin_products.field.name_en_helper')}
                    value={form.data.name_en}
                    check={checks.box('product-name-en', form.errors.name_en)}
                    onChange={(event) => form.setData('name_en', event.target.value)}
                />
                <SelectField id="product-brand-new" label={t('catalog::admin_products.field.brand')} value={form.data.brand_id} onChange={(event) => form.setData('brand_id', event.target.value)}>
                    {brands.map((brand) => (
                        <NativeSelectOption key={brand.id} value={brand.id}>
                            {nameIn(locale, brand.nameAr, brand.nameEn)}
                        </NativeSelectOption>
                    ))}
                </SelectField>
            </div>
        </PanelDialog>
    );
}
