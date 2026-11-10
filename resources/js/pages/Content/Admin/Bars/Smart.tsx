import { lazy, Suspense, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { BarsPageData, SmartBarData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';
import { BarsFrame } from './frame';
import { SmartBarsList } from './smart-list';

/*
| W2 · Bars, the Smart Bars tab (content.md §4.4, §1.9). Its dialog loads when it opens - never on first paint, so
| React.lazy is safe under SSR (lesson 180). A smart bar on a campaign waits for campaigns (plan PR 6).
*/
// A named export, not a default one: a file with a default export counts as a page of its own (JavaScriptBudgetTest).
const SmartBarDialog = lazy(() => import('./smart-dialog').then((module) => ({ default: module.SmartBarDialog })));

export default function Smart(page: BarsPageData) {
    const t = useTranslator();
    const storeId = page.stores.find((each) => each.code === page.storeCode)?.id ?? '';
    const [editing, setEditing] = useState<SmartBarData | 'new' | null>(null);

    return (
        <BarsFrame
            page={page}
            action={
                <Button type="button" onClick={() => setEditing('new')} data-test="add-smart-bar">
                    {t('content::admin_bars.smart.add')}
                </Button>
            }
        >
            <SmartBarsList rows={page.smartBars} categories={page.categories} onOpen={(row) => setEditing(row)} onAdd={() => setEditing('new')} />
            {editing === null ? null : (
                <Suspense fallback={null}>
                    <SmartBarDialog
                        key={editing === 'new' ? 'new' : editing.id}
                        row={editing === 'new' ? null : editing}
                        storeId={storeId}
                        zone={page.storeTimezone}
                        categories={page.categories}
                        translationReady={page.translationReady}
                        onClose={() => setEditing(null)}
                    />
                </Suspense>
            )}
        </BarsFrame>
    );
}
