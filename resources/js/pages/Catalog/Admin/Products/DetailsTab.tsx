import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { SearchCombobox } from '@/components/SearchCombobox';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { NativeSelectOption } from '@/components/ui/native-select';
import { useTranslator } from '@/lib/t';
import type { ProductHeadData, ProductPage } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { MarksField, nameIn, useLocale } from '../parts';
import { ProductShell } from './shell';

/*
| A product's Details (catalog.md §4.4 S9): both names, the web addresses (folded), the brand, the
| category - the lowest active ones, each by its path -, the warranty or none, the description in both
| languages (P1); the attributes its variants are made of are the Variants tab's (amendment 16(b)). Save Details is out of reach
| until something changes (Geist's Fieldset), and for whoever may not change the product.
*/

type DetailsForm = {
    name_ar: string;
    name_en: string;
    slug_ar: string;
    slug_en: string;
    brand_id: string;
    category_id: string;
    warranty_id: string;
    description_ar: string;
    description_en: string;
};

function initial(product: ProductHeadData): DetailsForm {
    return {
        name_ar: product.nameAr,
        name_en: product.nameEn ?? '',
        slug_ar: product.slugAr ?? '',
        slug_en: product.slugEn ?? '',
        brand_id: product.brandId,
        category_id: product.categoryId ?? '',
        warranty_id: product.warrantyId ?? '',
        description_ar: product.descriptionAr,
        description_en: product.descriptionEn,
    };
}

export function DetailsTab({ page }: { page: ProductPage }) {
    const { product, mayUpdate } = page;
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm<DetailsForm>(initial(product));
    const [addresses, setAddresses] = useState(false);
    const addressRefused = form.errors.slug_ar !== undefined || form.errors.slug_en !== undefined;
    const off = !mayUpdate;

    // The form follows the product as the server has it after each save.
    useEffect(() => {
        form.setDefaults(initial(product));
        form.reset();
    }, [product]);

    // A new choice takes an active brand, category or warranty; the product's own stays shown, whatever its state.
    const brands = (page.brands ?? []).filter((brand) => brand.active || brand.id === product.brandId);
    const brandOptions = brands.some((brand) => brand.id === product.brandId) ? brands : [{ id: product.brandId, nameAr: product.brandNameAr, nameEn: product.brandNameEn, isDefault: false, active: false }, ...brands];
    const categories = (page.categories ?? [])
        .filter((category) => category.active || category.id === product.categoryId)
        .map((category) => ({ value: category.id, label: locale === 'ar' ? category.pathAr : category.pathEn }));
    const categoryOptions =
        product.categoryId !== null && !categories.some((category) => category.value === product.categoryId)
            ? [{ value: product.categoryId, label: (locale === 'ar' ? product.categoryPathAr : product.categoryPathEn) ?? '' }, ...categories]
            : categories;
    const warranties = (page.warranties ?? []).filter((warranty) => warranty.active || warranty.id === product.warrantyId);

    return (
        <Card className="material-base border-0">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/admin/products/${product.id}/details`, { preserveScroll: true });
                }}
            >
                <CardContent className="grid gap-4 pt-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="details-name-ar" dir="rtl" disabled={off} label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} error={form.errors.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="details-name-ar" />
                        <TextField
                            id="details-name-en"
                            dir="ltr"
                            disabled={off}
                            label={t('catalog::admin.field.name_en')}
                            helper={product.stage === 'DRAFT' ? t('catalog::admin_products.field.name_en_helper') : undefined}
                            value={form.data.name_en}
                            error={form.errors.name_en}
                            onChange={(event) => form.setData('name_en', event.target.value)}
                            data-test="details-name-en"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField id="details-brand" disabled={off} label={t('catalog::admin_products.field.brand')} value={form.data.brand_id} onChange={(event) => form.setData('brand_id', event.target.value)} data-test="details-brand">
                            {brandOptions.map((brand) => (
                                <NativeSelectOption key={brand.id} value={brand.id}>
                                    {nameIn(locale, brand.nameAr, brand.nameEn)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                        {off ? (
                            <TextField id="details-category" disabled label={t('catalog::admin_products.field.category')} value={(locale === 'ar' ? product.categoryPathAr : product.categoryPathEn) ?? t('catalog::admin_products.none')} readOnly />
                        ) : (
                            <SearchCombobox
                                id="details-category"
                                label={t('catalog::admin_products.field.category')}
                                options={[{ value: '', label: t('catalog::admin_products.none') }, ...categoryOptions]}
                                value={form.data.category_id}
                                onChange={(category) => form.setData('category_id', category)}
                                words={{ search: t('catalog::admin_products.field.category_search'), none: (query) => t('catalog::admin_products.field.category_none', { query }) }}
                                data-test="details-category"
                            />
                        )}
                        <SelectField id="details-warranty" disabled={off} label={t('catalog::admin_products.field.warranty')} value={form.data.warranty_id} onChange={(event) => form.setData('warranty_id', event.target.value)} data-test="details-warranty">
                            <NativeSelectOption value="">{t('catalog::admin_products.none')}</NativeSelectOption>
                            {warranties.map((warranty) => (
                                <NativeSelectOption key={warranty.id} value={warranty.id}>
                                    {nameIn(locale, warranty.nameAr, warranty.nameEn)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>
                    </div>

                    <MarksField id="details-description-ar" dir="rtl" disabled={off} label={t('catalog::admin_products.field.description_ar')} value={form.data.description_ar} error={form.errors.description_ar} onChange={(value) => form.setData('description_ar', value)} />
                    <MarksField id="details-description-en" dir="ltr" disabled={off} label={t('catalog::admin_products.field.description_en')} value={form.data.description_en} error={form.errors.description_en} onChange={(value) => form.setData('description_en', value)} />

                    {/* A refused address is never left folded away out of sight. */}
                    <Collapsible open={addresses || addressRefused} onOpenChange={setAddresses}>
                        <CollapsibleTrigger asChild>
                            <Button type="button" variant="ghost" size="sm" className="justify-start px-0" data-test="details-addresses">
                                {t('catalog::admin.addresses.title')}
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="grid gap-4 pt-2 sm:grid-cols-2">
                            <TextField id="details-slug-ar" dir="rtl" disabled={off} label={t('catalog::admin.field.slug_ar')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_ar} error={form.errors.slug_ar} onChange={(event) => form.setData('slug_ar', event.target.value)} />
                            <TextField id="details-slug-en" dir="ltr" disabled={off} label={t('catalog::admin.field.slug_en')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_en} error={form.errors.slug_en} onChange={(event) => form.setData('slug_en', event.target.value)} />
                        </CollapsibleContent>
                    </Collapsible>
                </CardContent>
                <CardFooter className="justify-end">
                    <ActionButton
                        type="submit"
                        loading={form.processing}
                        disabledReason={off ? t('catalog::admin_products.read_only') : !form.isDirty ? t('catalog::admin_products.details.no_changes') : undefined}
                        data-test="save-details"
                    >
                        {t('catalog::admin_products.details.save')}
                    </ActionButton>
                </CardFooter>
            </form>
        </Card>
    );
}

/** The Details tab's page: this product above its tabs, this one open (ProductShell). */
export default function DetailsTabPage(page: ProductPage) {
    return (
        <ProductShell page={page}>
            <DetailsTab page={page} />
        </ProductShell>
    );
}
