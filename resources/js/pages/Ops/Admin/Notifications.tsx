import { usePage } from '@inertiajs/react';
import { BellPage } from '@/components/ops/BellPage';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { InboxPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| The panel's full page behind View All (ops.md §4.15 O1). No menu entry; the trail is Home › Notifications.
*/

export default function Notifications({ inbox }: { inbox: InboxPageData }) {
    const part = usePage<SharedProps>().props.panelFrame?.ops;
    const t = useTranslator();

    if (part === undefined) {
        return null;
    }

    return (
        <AdminLayout title={part.words.title} breadcrumbs={[{ label: t('admin.home.title'), href: '/admin' }]}>
            <BellPage inbox={inbox} base="/admin/notifications" words={part.words} manyStores={part.manyStores} />
        </AdminLayout>
    );
}
