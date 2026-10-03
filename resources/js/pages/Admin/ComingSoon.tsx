import { EmptyState } from '@/components/geist';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { ComingSoonPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A screen whose module is not built yet (frontend.md §2.2), in Geist (1.10): an Empty State, since
| there is nothing on it yet and one sentence says why.
|
| Only a Super Admin is ever offered one of these: the permissions that would gate it do not exist,
| so there is nothing to check, and a menu entry nobody can check must not be offered to everybody.
*/

type Props = ComingSoonPage;

export default function ComingSoon({ label }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout title={label} subtitle={t('admin.coming_soon_subtitle')}>
            <EmptyState title={t('admin.coming_soon_title')} description={t('admin.coming_soon_body')} />
        </AdminLayout>
    );
}
