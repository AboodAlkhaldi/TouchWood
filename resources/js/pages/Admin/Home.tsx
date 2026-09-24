import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';

/*
| Where the panel opens once somebody is signed in.
|
| It carries no figures yet: every number an admin home would show belongs to a module that is not
| built (orders, sales, stock). Step 1 needs it because A9 - signing out - lives in the sidebar, and
| because the foundation is only proved if a real page wears the admin frame.
*/

export default function Home() {
    const t = useTranslator();

    return (
        <AdminLayout title={t('admin.home.title')} subtitle={t('admin.home.subtitle')}>
            <div className="rounded-lg border border-line bg-surface p-6 shadow-card">
                <p className="text-sm text-ink-muted">{t('admin.home.empty')}</p>
            </div>
        </AdminLayout>
    );
}
