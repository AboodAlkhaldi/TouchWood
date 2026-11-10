import { Link, router } from '@inertiajs/react';
import { PhoneCards, StateBadge } from '@/components/content/list';
import { ScheduleMenu, type ScheduleWords } from '@/components/content/schedule-menu';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useKeptDepth } from '@/lib/kept-depth';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { PopupRowData, PopupsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W6 · Pop-Ups (content.md §4.4): the store's pop-ups, newest first, 50 at a time with Show More. The whole row opens
| the pop-up's own page; its menu ends one that is live, takes one scheduled back to draft, deletes a draft.
*/

const LIST = '/admin/content/popups';

export default function Index({ stores, storeCode, popups, next }: PopupsPageData) {
    const t = useTranslator();
    const list = useLoadMore<PopupRowData>(popups, (popup) => popup.id);
    const more = (cursor: string) => list.more(LIST, { store: storeCode, after: cursor }, ['popups', 'next']);
    const restoring = useKeptDepth(list, next, more);
    const newPopup = `${LIST}/new?store=${encodeURIComponent(storeCode)}`;
    const words: ScheduleWords = {
        edit: t('content::admin_popups.menu.edit'),
        end: t('content::admin_popups.menu.end'),
        draft: t('content::admin_popups.menu.draft'),
        delete: t('content::admin_popups.menu.delete'),
        endTitle: t('content::admin_popups.end.title'),
        endBody: t('content::admin_popups.end.body'),
        endConfirm: t('content::admin_popups.end.confirm'),
        deleteTitle: t('content::admin_popups.delete.title'),
        deleteBody: t('content::admin_popups.delete.body'),
        deleteConfirm: t('content::admin_popups.delete.confirm'),
    };
    const starts = (row: PopupRowData) => (row.startsAt === null ? t('content::admin_popups.now') : <Time value={row.startsAt} mode="absolute" focusable={false} />);
    const ends = (row: PopupRowData) => (row.endsAt === null ? t('content::admin_popups.no_end') : <Time value={row.endsAt} mode="absolute" focusable={false} />);
    const menu = (row: PopupRowData) => <ScheduleMenu id={row.id} name={row.name} state={row.state} base={LIST} words={words} />;

    return (
        <AdminLayout
            title={t('content::admin_popups.title')}
            subtitle={t('content::admin_popups.subtitle')}
            action={
                <Button asChild>
                    <Link href={newPopup} data-test="new-popup">
                        {t('content::admin_popups.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={storeCode} className="max-w-xs" />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="popups-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_popups.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_popups.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={newPopup}>{t('content::admin_popups.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('content::admin_popups.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_popups.columns.name')}</TableHead>
                                        <TableHead>{t('content::admin_popups.columns.state')}</TableHead>
                                        <TableHead>{t('content::admin_popups.columns.starts')}</TableHead>
                                        <TableHead>{t('content::admin_popups.columns.ends')}</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('content::admin_shared.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`${LIST}/${row.id}`)} data-test={`popup-${row.id}`}>
                                            <TableCell className="max-w-80 truncate text-label-14 text-ink">
                                                <Link href={`${LIST}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                                                    {row.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <StateBadge state={row.state} />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{starts(row)}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{ends(row)}</TableCell>
                                            <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                                                {menu(row)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="popup"
                            cards={list.rows.map((row) => ({
                                id: row.id,
                                title: row.name,
                                badge: <StateBadge state={row.state} />,
                                lines: [
                                    <span key="dates">
                                        {starts(row)} – {ends(row)}
                                    </span>,
                                ],
                                menu: menu(row),
                                onOpen: () => router.visit(`${LIST}/${row.id}`),
                            }))}
                        />
                        {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
