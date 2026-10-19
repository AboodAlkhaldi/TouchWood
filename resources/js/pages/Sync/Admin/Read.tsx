import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { SyncReadPage } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';

/*
| **A read** (sync.md §4.1 Y2) - a PLACEHOLDER of stage 5's screens step 6, which built the server's side
| only: its props are typed and arrive whole. Step 7 draws the page - the header and the section tabs.
*/
export default function Read(page: SyncReadPage) {
    const t = useTranslator();

    return (
        <AdminLayout title={t('sync::admin_report.read.title', { date: page.read.queuedAt })}>
            <p className="text-copy-14 text-ink-muted" data-test="sync-read-placeholder">
                {page.storeCode}
            </p>
        </AdminLayout>
    );
}
