import { DocumentForm } from '@/components/content/document-form';
import { ImageBox } from '@/components/content/image-box';
import { PairFields } from '@/components/content/parts';
import { ProductsField } from '@/components/content/products-field';
import { SelectField } from '@/components/Fields';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { DocumentFormPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W8 · New Post and a post's own page (content.md §4.4, §1.12): as a page, plus its one-line summary, its cover and the
| cover's description, its category, and up to 8 linked products. Publish Post needs the summary, the cover and its
| description in both languages.
*/

export default function Form(page: DocumentFormPageData) {
    const t = useTranslator();
    const name = useInLanguage();

    return (
        <DocumentForm
            page={page}
            words={{
                list: t('content::admin_blog.title'),
                new: t('content::admin_blog.new'),
                item: t('content::admin_blog.item'),
                publish: t('content::admin_blog.publish'),
                unpublishMenu: t('content::admin_blog.unpublish.menu'),
                unpublishTitle: t('content::admin_blog.unpublish.title'),
                unpublishBody: t('content::admin_blog.unpublish.body'),
                unpublishConfirm: t('content::admin_blog.unpublish.confirm'),
                deleteMenu: t('content::admin_blog.delete.menu'),
                deleteTitle: t('content::admin_blog.delete.title'),
                deleteBody: t('content::admin_blog.delete.body'),
                deleteConfirm: t('content::admin_blog.delete.confirm'),
            }}
            extraChecks={(data, published) => [
                { id: 'summary_ar', label: t('content::admin_blog.fields.summary_ar'), value: data.summary_ar, rules: { required: published, length: { max: 200 } } },
                { id: 'summary_en', label: t('content::admin_blog.fields.summary_en'), value: data.summary_en, rules: { required: published, length: { max: 200 } } },
                { id: 'cover_alt_ar', label: t('content::admin_blog.fields.cover_alt_ar'), value: data.cover_alt_ar, rules: { required: published, length: { max: 200 } } },
                { id: 'cover_alt_en', label: t('content::admin_blog.fields.cover_alt_en'), value: data.cover_alt_en, rules: { required: published, length: { max: 200 } } },
            ]}
        >
            {({ form, errors, checks, translation, set, cover, setCover, products, setProducts, uploadKind, storeId }) => (
                <>
                    <PairFields name="summary" labelAr={t('content::admin_blog.fields.summary_ar')} labelEn={t('content::admin_blog.fields.summary_en')} helper={t('content::admin_blog.fields.summary_rule')} ar={form.data.summary_ar} en={form.data.summary_en} onChange={set('summary')} errors={errors} checks={checks} translation={translation} />
                    <ImageBox id="cover" label={t('content::admin_blog.fields.cover')} helper={t('content::admin_blog.fields.cover_helper')} kind={uploadKind} store={storeId} image={cover} onChange={setCover} size={{ width: 1600, height: 900 }} error={errors.cover} />
                    <PairFields name="cover_alt" labelAr={t('content::admin_blog.fields.cover_alt_ar')} labelEn={t('content::admin_blog.fields.cover_alt_en')} helper={t('content::admin_blog.fields.cover_alt_rule')} ar={form.data.cover_alt_ar} en={form.data.cover_alt_en} onChange={set('cover_alt')} errors={errors} checks={checks} translation={translation} />
                    <SelectField id="category" className="max-w-sm" label={t('content::admin_blog.fields.category')} error={errors.category} value={form.data.category_id} onChange={(event) => form.setData('category_id', event.target.value)} data-test="category">
                        <option value="">{t('content::admin_blog.fields.category_none')}</option>
                        {page.categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {name(category.nameAr, category.nameEn)}
                            </option>
                        ))}
                    </SelectField>
                    <ProductsField products={products} onChange={setProducts} max={8} search="/admin/content/blog/products" store={storeId} error={errors.products} />
                </>
            )}
        </DocumentForm>
    );
}
