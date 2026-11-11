import { lazy, Suspense, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { MoveItems, SortableList } from '@/components/content/sortable';
import { FormError } from '@/components/FormError';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { BlogCategoriesPageData, BlogCategoryData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { BlogTabs } from './tabs';

/*
| W8 · Blog, its Categories tab (content.md §4.4, §1.12): the store's categories in their order - dragged, or moved to the
| top or the bottom from each row's ⋯ - each with how many posts it holds. Add Category and Edit open a dialog of the
| name and address per language, with Translate (loaded when opened); Delete… is out of reach, with the reason, while it
| holds posts.
*/

const CategoryDialog = lazy(() => import('./category-dialog').then((module) => ({ default: module.CategoryDialog })));
const ScheduleConfirm = lazy(() => import('@/components/content/schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));

const BASE = '/admin/content/blog/categories';

export default function Categories({ stores, storeCode, categories, postsCount, translationReady }: BlogCategoriesPageData) {
    const t = useTranslator();
    const name = useInLanguage();
    const store = stores.find((each) => each.code === storeCode);
    const [editing, setEditing] = useState<BlogCategoryData | 'new' | null>(null);
    const [deleting, setDeleting] = useState<BlogCategoryData | null>(null);
    const [busy, setBusy] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const order = (ids: string[]) => router.post(`${BASE}/order`, { store: store?.id ?? '', ids }, { preserveScroll: true });
    const ids = categories.map((category) => category.id);

    return (
        <AdminLayout
            title={t('content::admin_blog.title')}
            subtitle={t('content::admin_blog.subtitle')}
            action={
                <Button type="button" onClick={() => setEditing('new')} data-test="add-category">
                    {t('content::admin_blog.categories.add')}
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={storeCode} className="max-w-xs" />
                <BlogTabs tab="categories" storeCode={storeCode} posts={postsCount} categories={categories.length} />
                {categories.length === 0 ? (
                    <Empty className="material-base" data-test="categories-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_blog.categories.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_blog.categories.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button type="button" onClick={() => setEditing('new')}>
                                {t('content::admin_blog.categories.add')}
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <SortableList
                        testPrefix="category"
                        onChange={order}
                        items={categories.map((category, at) => ({
                            id: category.id,
                            label: name(category.nameAr, category.nameEn),
                            content: (
                                <span className="grid min-w-0">
                                    <span className="truncate text-copy-14 text-ink">{name(category.nameAr, category.nameEn)}</span>
                                    <span className="text-copy-13 text-ink-muted">{t('content::admin_blog.categories.posts', { count: category.posts })}</span>
                                </span>
                            ),
                            extra: (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <MoreButton ref={more} name={name(category.nameAr, category.nameEn)} data-test={`category-more-${at}`} />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="min-w-48">
                                        <DropdownMenuItem onSelect={() => setEditing(category)} data-test="category-edit">
                                            {t('content::admin_blog.categories.menu.edit')}
                                        </DropdownMenuItem>
                                        <MoveItems first={at === 0} last={at === categories.length - 1} onTop={() => order([category.id, ...ids.filter((id) => id !== category.id)])} onBottom={() => order([...ids.filter((id) => id !== category.id), category.id])} testPrefix="category-move" />
                                        <DropdownMenuSeparator />
                                        <Guarded destructive label={`${t('content::admin_blog.categories.menu.delete')}…`} reason={category.posts > 0 ? t('content::admin_blog.categories.holds_posts') : null} onSelect={() => setDeleting(category)} test="category-delete" />
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ),
                        }))}
                    />
                )}
            </div>
            {editing === null ? null : (
                <Suspense fallback={null}>
                    <CategoryDialog storeId={store?.id ?? ''} category={editing === 'new' ? null : editing} translationReady={translationReady} onClose={() => setEditing(null)} />
                </Suspense>
            )}
            {deleting === null ? null : (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={more}
                        title={t('content::admin_blog.categories.delete.title')}
                        description={t('content::admin_blog.categories.delete.body')}
                        label={t('content::admin_blog.categories.delete.confirm')}
                        busy={busy}
                        onConfirm={() =>
                            router.post(
                                `${BASE}/${deleting.id}/delete`,
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => {
                                        setBusy(false);
                                        setDeleting(null);
                                    },
                                },
                            )
                        }
                        onClose={() => setDeleting(null)}
                        test="confirm-delete-category"
                    />
                </Suspense>
            )}
        </AdminLayout>
    );
}
