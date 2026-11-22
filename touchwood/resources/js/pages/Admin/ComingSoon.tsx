import { AdminLayout } from '@/layouts/AdminLayout';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { useTranslator } from '@/lib/t';
import type { ComingSoonPage } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| A screen whose module is not built yet (frontend.md §2.2): shadcn's Empty (Geist's Empty State),
| since there is nothing on it yet and one sentence says why.
|
| Only a Super Admin is ever offered one of these: the permissions that would gate it do not exist,
| so there is nothing to check, and a menu entry nobody can check must not be offered to everybody.
*/

type Props = ComingSoonPage;

export default function ComingSoon({ label }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout title={label} subtitle={t('admin.coming_soon_subtitle')}>
            <Empty className="material-base">
                <EmptyHeader>
                    <EmptyTitle>{t('admin.coming_soon_title')}</EmptyTitle>
                    <EmptyDescription>{t('admin.coming_soon_body')}</EmptyDescription>
                </EmptyHeader>
            </Empty>
        </AdminLayout>
    );
}
