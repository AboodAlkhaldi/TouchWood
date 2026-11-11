import { router } from '@inertiajs/react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';

/*
| W1's two tabs, Sections and Footer (content.md §4.4), underlined, Sections with its count. Each tab is its own page
| (`?tab=`), so the sections' page carries no form's code (JavaScriptBudgetTest). Both pages draw them from here.
*/
export function HomeTabs({ tab, storeCode, sections }: { tab: 'sections' | 'footer'; storeCode: string; sections: number }) {
    const t = useTranslator();

    return (
        <div className="min-w-0 max-w-full overflow-x-auto" data-test="tabs-row">
            <Tabs value={tab} onValueChange={(next) => router.get('/admin/content/home', { store: storeCode, tab: next }, { preserveScroll: true })}>
                <TabsList variant="line" className="max-w-full overflow-x-auto">
                    <TabsTrigger value="sections" data-test="tab-sections">
                        {t('content::admin_home.tabs.sections')}
                        <span className="tw-figure text-ink-muted">{sections}</span>
                    </TabsTrigger>
                    <TabsTrigger value="footer" data-test="tab-footer">
                        {t('content::admin_home.tabs.footer')}
                    </TabsTrigger>
                </TabsList>
            </Tabs>
        </div>
    );
}
