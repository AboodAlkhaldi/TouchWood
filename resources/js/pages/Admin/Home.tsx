import { Link, usePage } from '@inertiajs/react';
import { Note } from '@/components/geist';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import type { MenuEntry, SharedProps } from '@/types/page';

/*
| Where the panel opens once somebody is signed in, in Geist (1.10).
|
| It carries no figures yet: every number an admin home would show belongs to a module that is not
| built (orders, sales, stock). Step 1 needs it because A9 - signing out - lives in the sidebar, and
| because the foundation is only proved if a real page wears the admin frame.
|
| What it does say is what waits for this person: every menu entry with a count above nothing -
| the failed jobs first of all (frontend.md E7, owner 2026-09-29). It reads the menu the person was
| offered, so nobody is told about a screen they may not open. They are one concept, so one Geist
| Note, in the warning colour Geist gives "something to act on"; it stays until nothing is waiting.
*/

export default function Home() {
    const t = useTranslator();
    const { menu } = usePage<SharedProps>().props;
    const waiting: MenuEntry[] = menu.flatMap((group) => group.entries).filter((entry) => entry.count !== null && entry.count > 0);

    return (
        <AdminLayout title={t('admin.home.title')} subtitle={t('admin.home.subtitle')}>
            <div className="grid gap-4">
                {waiting.length > 0 ? (
                    <Note variant="warning">
                        <ul className="grid gap-1" role="status">
                            {waiting.map((entry) => (
                                <li key={`${entry.module}.${entry.key}`}>
                                    <Link
                                        href={entry.href}
                                        className="font-medium hover:underline"
                                        data-test={`waiting-${entry.module}.${entry.key}`}
                                    >
                                        {t('admin.home.waiting', { label: entry.label, count: String(entry.count) })}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Note>
                ) : null}

                <div className="material-base p-5 sm:p-6">
                    <p className="text-copy-14 text-ink-muted">{t('admin.home.empty')}</p>
                </div>
            </div>
        </AdminLayout>
    );
}
