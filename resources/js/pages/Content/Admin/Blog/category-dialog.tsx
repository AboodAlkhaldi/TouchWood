import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PairFields } from '@/components/content/parts';
import { useReadTick, useTranslation } from '@/components/content/translate';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { BlogCategoryData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| Add Category and Edit Category (content.md §1.12, W8): the name and the address per language, both languages to save -
| a category is shown at once (§1.1 rule 2) - so the one tick is asked while Translate's mark stays on (§1.3). Loaded
| when opened. A named export: a default one would count as a page.
*/

export function CategoryDialog({ storeId, category, translationReady, onClose }: { storeId: string; category: BlogCategoryData | null; translationReady: boolean; onClose: () => void }) {
    const t = useTranslator();
    const form = useForm({ name_ar: category?.nameAr ?? '', name_en: category?.nameEn ?? '', slug_ar: category?.slugAr ?? '', slug_en: category?.slugEn ?? '' });
    const translation = useTranslation('blog', storeId, translationReady);
    const tick = useReadTick(translation);
    const checks = useChecks([
        { id: 'name_ar', label: t('content::admin_blog.categories.name_ar'), value: form.data.name_ar, rules: { required: true, length: { max: 80 } } },
        { id: 'name_en', label: t('content::admin_blog.categories.name_en'), value: form.data.name_en, rules: { required: true, length: { max: 80 } } },
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    const label = t('content::admin_blog.categories.save');
    const send = () => {
        form.transform((data) => ({ ...data, store: storeId }));
        form.post(category === null ? '/admin/content/blog/categories' : `/admin/content/blog/categories/${category.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <>
            <PanelDialog
                open
                onOpenChange={(open) => (open ? undefined : onClose())}
                title={category === null ? t('content::admin_blog.categories.add') : t('content::admin_blog.categories.edit')}
                description={t('content::admin_blog.categories.name_rule')}
                busy={form.processing}
                confirm={
                    <ActionButton type="button" loading={form.processing} disabledReason={checks.reason} onClick={() => checks.submit(() => tick.ask(send, label))} data-test="save-category">
                        {label}
                    </ActionButton>
                }
            >
                <div className="grid gap-4">
                    <PairFields name="name" labelAr={t('content::admin_blog.categories.name_ar')} labelEn={t('content::admin_blog.categories.name_en')} ar={form.data.name_ar} en={form.data.name_en} onChange={(language, value) => form.setData(`name_${language}`, value)} errors={errors} checks={checks} translation={translation} />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField id="slug_ar" dir="rtl" lang="ar" label={t('content::admin_blog.categories.slug_ar')} helper={t('content::admin_documents.fields.slug_helper')} error={errors.slug_ar} value={form.data.slug_ar} onChange={(event) => form.setData('slug_ar', event.target.value)} data-test="slug_ar" />
                        <TextField id="slug_en" dir="ltr" lang="en" label={t('content::admin_blog.categories.slug_en')} error={errors.slug_en} value={form.data.slug_en} onChange={(event) => form.setData('slug_en', event.target.value)} data-test="slug_en" />
                    </div>
                </div>
            </PanelDialog>
            {translation.dialog}
            {tick.dialog}
        </>
    );
}
