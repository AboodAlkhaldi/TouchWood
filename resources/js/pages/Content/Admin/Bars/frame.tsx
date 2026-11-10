import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { FormError } from '@/components/FormError';
import { StoreFilter } from '@/components/StoreFilter';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { BarsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W2's frame (content.md §4.4): the title, the store filter, and the two underlined tabs, Smart Bars with its count. Each
| tab is its own page (`?tab=`), so neither carries the other's code (JavaScriptBudgetTest).
*/
export function BarsFrame({ page, action, children }: { page: BarsPageData; action?: ReactNode; children: ReactNode }) {
    const t = useTranslator();

    return (
        <AdminLayout title={t('content::admin_bars.title')} subtitle={t('content::admin_bars.subtitle')} action={action}>
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={page.stores.map((each) => ({ code: each.code, name: each.name, isActive: each.isOn }))} value={page.storeCode} drop={['tab']} className="max-w-xs" />
                <div className="min-w-0 max-w-full overflow-x-auto" data-test="tabs-row">
                    <Tabs value={page.tab} onValueChange={(next) => router.get('/admin/content/bars', { store: page.storeCode, tab: next }, { preserveScroll: true })}>
                        <TabsList variant="line" className="max-w-full overflow-x-auto">
                            <TabsTrigger value="announcement" data-test="tab-announcement">
                                {t('content::admin_bars.tabs.announcement')}
                            </TabsTrigger>
                            <TabsTrigger value="smart" data-test="tab-smart">
                                {t('content::admin_bars.tabs.smart')}
                                <span className="tw-figure text-ink-muted">{page.smartBars.length}</span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
                {children}
            </div>
        </AdminLayout>
    );
}
