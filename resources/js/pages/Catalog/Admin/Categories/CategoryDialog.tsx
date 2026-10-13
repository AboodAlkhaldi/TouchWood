import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField, TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { NativeSelectOption } from '@/components/ui/native-select';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CategoryData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { ImageField } from '../ImageField';
import { nameIn, useLocale } from '../parts';
import { SLUG_RULES, slugsWrong } from '../slugs';

/*
| The category's add, edit and move dialog (catalog.md §1.5, §4.4 S2): a module of its own, loaded
| when the categories screen first opens it, so the page itself stays within its budget
| (frontend.md §5).
*/

type CategoryForm = {
    name_ar: string;
    name_en: string;
    slug_ar: string;
    slug_en: string;
    parent_id: string;
    image_media_id: string;
    image: File | null;
    remove_image: boolean;
};

/** Add a category (under a parent, or at the top), edit one's names and photo, or move one. */
export function CategoryDialog({
    mode,
    category,
    parent,
    categories,
    excluded,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    mode: 'add' | 'edit' | 'move';
    category: CategoryData | null;
    parent: string | null;
    categories: CategoryData[];
    /** Itself and everything under it: never its own new parent (CategoryLoop). */
    excluded: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = (): CategoryForm => ({
        name_ar: category?.nameAr ?? '',
        name_en: category?.nameEn ?? '',
        slug_ar: category?.slugAr ?? '',
        slug_en: category?.slugEn ?? '',
        parent_id: mode === 'add' ? (parent ?? '') : (category?.parentId ?? ''),
        image_media_id: category?.imageMediaId ?? '',
        image: null,
        remove_image: false,
    });
    const form = useForm<CategoryForm>(initial());
    const [addresses, setAddresses] = useState(false);
    // A refused address is never left folded away out of sight.
    const addressRefused = form.errors.slug_ar !== undefined || form.errors.slug_en !== undefined;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
            setAddresses(false);
        }
    }, [open, category?.id, parent]);

    // A parent is an active category holding no products itself (CategoryHoldsProducts).
    const parents = categories.filter((each) => each.active && each.productsHere === 0 && !excluded.includes(each.id));
    const title = mode === 'add' ? (parent === null ? t('catalog::admin_categories.add') : t('catalog::admin_categories.add_sub_title')) : mode === 'edit' ? t('catalog::admin_categories.edit_title') : t('catalog::admin_categories.move_title');
    const body = mode === 'add' ? t('catalog::admin_categories.add_body') : mode === 'edit' ? t('catalog::admin_categories.edit_body') : t('catalog::admin_categories.move_body');

    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 100 characters
    // (Category::NAME_MAX, LocalizedName), not sent by a move; and the web addresses (SLUG_RULES), not
    // sent by a move, their section open while one is wrong. A category added or moved goes last among
    // its siblings in every store; it has no place to type (amendment 16(d)).
    const name = { required: true, length: { max: 100 } };
    const checks = useChecks([
        { id: 'category-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name, off: mode === 'move' },
        { id: 'category-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name, off: mode === 'move' },
        { id: 'category-slug-ar', label: t('catalog::admin.field.slug_ar'), value: form.data.slug_ar, rules: SLUG_RULES.ar, off: mode === 'move' },
        { id: 'category-slug-en', label: t('catalog::admin.field.slug_en'), value: form.data.slug_en, rules: SLUG_RULES.en, off: mode === 'move' },
    ]);

    function submit() {
        const target = mode === 'add' ? '/admin/categories' : mode === 'edit' ? `/admin/categories/${category?.id ?? ''}` : `/admin/categories/${category?.id ?? ''}/move`;
        // Sent as multipart only when a photo file is attached (Inertia does that by itself).
        checks.submit(() => form.post(target, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            wide={mode !== 'move'}
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={body}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test={`confirm-${mode}-category`}>
                    {mode === 'add' ? title : mode === 'edit' ? t('catalog::admin_categories.save') : t('catalog::admin_categories.move_confirm')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                {mode === 'move' ? null : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="category-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} value={form.data.name_ar} check={checks.box('category-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="category-name-ar" />
                        <TextField id="category-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} value={form.data.name_en} check={checks.box('category-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="category-name-en" />
                    </div>
                )}

                {mode === 'edit' ? null : (
                    <SelectField id="category-parent" label={t('catalog::admin_categories.field.parent')} value={form.data.parent_id} error={form.errors.parent_id} onChange={(event) => form.setData('parent_id', event.target.value)} data-test="category-parent">
                        <NativeSelectOption value="">{t('catalog::admin_categories.field.top')}</NativeSelectOption>
                        {parents.map((each) => (
                            <NativeSelectOption key={each.id} value={each.id}>
                                {nameIn(locale, each.nameAr, each.nameEn)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                )}

                {mode === 'move' ? null : (
                    <>
                        <ImageField
                            id="category-image"
                            label={t('catalog::admin_categories.field.image')}
                            held={(category?.imageMediaId ?? null) !== null}
                            current={category?.image ?? null}
                            onFile={(file) => form.setData('image', file)}
                            remove={form.data.remove_image}
                            onRemove={(remove) => form.setData('remove_image', remove)}
                            error={form.errors.image_media_id}
                        />
                        <Collapsible open={addresses || addressRefused || slugsWrong(form.data.slug_ar, form.data.slug_en)} onOpenChange={setAddresses}>
                            <CollapsibleTrigger asChild>
                                <Button type="button" variant="ghost" size="sm" className="justify-start px-0" data-test="category-addresses">
                                    {t('catalog::admin.addresses.title')}
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent className="grid gap-4 pt-2 sm:grid-cols-2">
                                <TextField id="category-slug-ar" dir="rtl" label={t('catalog::admin.field.slug_ar')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_ar} check={checks.box('category-slug-ar', form.errors.slug_ar)} onChange={(event) => form.setData('slug_ar', event.target.value)} />
                                <TextField id="category-slug-en" dir="ltr" label={t('catalog::admin.field.slug_en')} helper={t('catalog::admin.addresses.helper')} value={form.data.slug_en} check={checks.box('category-slug-en', form.errors.slug_en)} onChange={(event) => form.setData('slug_en', event.target.value)} />
                            </CollapsibleContent>
                        </Collapsible>
                    </>
                )}
            </div>
        </PanelDialog>
    );
}
