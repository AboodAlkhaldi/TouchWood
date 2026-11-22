import { router } from '@inertiajs/react';
import { DocumentList } from '@/components/content/document-list';
import { SelectField } from '@/components/Fields';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { DocumentsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { BlogTabs } from './tabs';

/*
| W8 · Blog, its Posts tab (content.md §4.4, §1.12): every post of the store, last changed first, filtered by category;
| each row opens its post.
*/

export default function Index(page: DocumentsPageData) {
    const t = useTranslator();
    const name = useInLanguage();

    return (
        <DocumentList
            page={page}
            base="/admin/content/blog"
            form="/admin/content/blog/posts"
            words={{ title: t('content::admin_blog.title'), subtitle: t('content::admin_blog.subtitle'), new: t('content::admin_blog.new'), empty: t('content::admin_blog.empty'), emptyBody: t('content::admin_blog.empty_body') }}
            tabs={<BlogTabs tab="posts" storeCode={page.storeCode} posts={page.postsCount} categories={page.categories.length} />}
            filter={
                page.categories.length === 0 ? null : (
                    <SelectField
                        id="category-filter"
                        className="max-w-xs"
                        label={t('content::admin_blog.filter')}
                        value={page.category ?? ''}
                        onChange={(event) => router.get('/admin/content/blog', { store: page.storeCode, ...(event.target.value === '' ? {} : { category: event.target.value }) }, { preserveScroll: true })}
                        data-test="category-filter"
                    >
                        <option value="">{t('content::admin_blog.all_categories')}</option>
                        {page.categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {name(category.nameAr, category.nameEn)}
                            </option>
                        ))}
                    </SelectField>
                )
            }
        />
    );
}
