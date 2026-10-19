import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { ProviderPage } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';

/*
| **The Provider page** (sync.md §4.1 Y3) - a PLACEHOLDER of stage 5's screens step 6, which built the
| server's side only: its props are typed and arrive whole. Step 7 draws the page - the wiring form, the
| wired store's description and Unwire Store.
*/
export default function Provider(page: ProviderPage) {
    const t = useTranslator();

    return (
        <AdminLayout title={t('sync::admin_provider.title')} subtitle={t('sync::admin_provider.subtitle')}>
            <p className="text-copy-14 text-ink-muted" data-test="sync-provider-placeholder">
                {page.storeFrame.storeName}
            </p>
        </AdminLayout>
    );
}
