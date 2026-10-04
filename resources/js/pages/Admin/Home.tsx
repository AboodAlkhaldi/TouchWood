import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { Note } from '@/components/Note';
import { Badge } from '@/components/ui/badge';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Item, ItemActions, ItemContent, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { MenuEntry, SharedProps } from '@/types/page';

/*
| Where the panel opens once somebody is signed in (frontend.md §2.2), on shadcn's parts with
| Geist's rules (§1.11).
|
| It carries no figures yet: every number an admin home would show belongs to a module that is not
| built (orders, sales, stock).
|
| What it does say is what waits for this person: every menu entry with a count above nothing - the
| failed jobs first of all (frontend.md E7, owner 2026-09-29). It reads the menu the person was
| offered, so nobody is told about a screen they may not open. One linked row per entry, its count
| as a badge, under one warning Note with a label and a sentence (owner, 2026-10-03; Geist's Note
| holds one sentence and at most one action, and a list of things to open is rows). The Note stays
| until nothing is waiting.
*/

export default function Home() {
    const t = useTranslator();
    const { menu } = usePage<SharedProps>().props;
    const waiting: MenuEntry[] = menu.flatMap((group) => group.entries).filter((entry) => entry.count !== null && entry.count > 0);

    return (
        <AdminLayout title={t('admin.home.title')} subtitle={t('admin.home.subtitle')}>
            <div className="grid gap-4">
                {waiting.length > 0 ? (
                    <section className="grid gap-3" role="status" aria-labelledby="waiting-label">
                        <Note variant="warning" label={<span id="waiting-label">{t('admin.home.waiting_label')}</span>}>
                            {t('admin.home.waiting_note')}
                        </Note>

                        <ItemGroup className="material-base">
                            {waiting.map((entry, index) => (
                                <div key={`${entry.module}.${entry.key}`} role="listitem">
                                    {index === 0 ? null : <ItemSeparator />}
                                    <Item asChild size="sm" className="rounded-none">
                                        <Link href={entry.href} data-test={`waiting-${entry.module}.${entry.key}`}>
                                            <ItemContent>
                                                <ItemTitle className="text-label-14 text-ink">{entry.label}</ItemTitle>
                                            </ItemContent>
                                            <ItemActions>
                                                {/* The number for the eye; the same said in words for a screen reader,
                                                    as the sidebar says it. */}
                                                <Badge className={`tw-figure ${tone('amber-subtle')}`} aria-hidden="true">
                                                    {entry.count}
                                                </Badge>
                                                <span className="sr-only">{t('admin.menu_waiting', { count: String(entry.count) })}</span>
                                                <ChevronRight aria-hidden="true" className="size-4 text-ink-muted rtl:rotate-180" />
                                            </ItemActions>
                                        </Link>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    </section>
                ) : (
                    // Said only while nothing waits: under the waiting rows it would contradict them.
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle>{t('admin.home.empty_title')}</EmptyTitle>
                            <EmptyDescription>{t('admin.home.empty')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                )}
            </div>
        </AdminLayout>
    );
}
