import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { SyncReportPage } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';

/*
| **The Sync Report** (sync.md §4.1 Y1) - a PLACEHOLDER of stage 5's screens step 6, which built the
| server's side only: its props are typed and arrive whole. Step 7 draws the page - the status line, the
| Problems and Reads tabs, Sync Now and each entry's ⋯ menu.
*/
export default function Report(page: SyncReportPage) {
    const t = useTranslator();

    return (
        <AdminLayout title={t('sync::admin_report.title')} subtitle={t('sync::admin_report.subtitle')}>
            <p className="text-copy-14 text-ink-muted" data-test="sync-report-placeholder">
                {page.storeFrame.storeName}
            </p>
        </AdminLayout>
    );
}
