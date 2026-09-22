import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { ComingSoonPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A screen whose module is not built yet (frontend.md §2.2).
|
| Only a Super Admin is ever offered one of these: the permissions that would gate it do not exist,
| so there is nothing to check, and a menu entry nobody can check must not be offered to everybody.
*/

type Props = ComingSoonPage;

export default function ComingSoon({ label }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout title={label} subtitle={t('admin.coming_soon_subtitle')}>
            <div className="rounded-lg border border-dashed border-line-strong bg-surface p-10 text-center">
                <p className="text-sm text-ink-muted">{t('admin.coming_soon_body')}</p>
            </div>
        </AdminLayout>
    );
}
