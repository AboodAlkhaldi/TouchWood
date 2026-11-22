import { router } from '@inertiajs/react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';

/*
| W8's two tabs, Posts and Categories (content.md §4.4), underlined, each with its count. Each tab is its own page, so the
| posts' list carries none of the categories' dragging and dialog (JavaScriptBudgetTest).
*/
export function BlogTabs({ tab, storeCode, posts, categories }: { tab: 'posts' | 'categories'; storeCode: string; posts: number; categories: number }) {
    const t = useTranslator();

    return (
        <div className="min-w-0 max-w-full overflow-x-auto" data-test="tabs-row">
            <Tabs value={tab} onValueChange={(next) => router.get(next === 'posts' ? '/admin/content/blog' : '/admin/content/blog/categories', { store: storeCode }, { preserveScroll: true })}>
                <TabsList variant="line">
                    <TabsTrigger value="posts" data-test="tab-posts">
                        {t('content::admin_blog.tabs.posts')}
                        <span className="tw-figure text-ink-muted">{posts}</span>
                    </TabsTrigger>
                    <TabsTrigger value="categories" data-test="tab-categories">
                        {t('content::admin_blog.tabs.categories')}
                        <span className="tw-figure text-ink-muted">{categories}</span>
                    </TabsTrigger>
                </TabsList>
            </Tabs>
        </div>
    );
}
