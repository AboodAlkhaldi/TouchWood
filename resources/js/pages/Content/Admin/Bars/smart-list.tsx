import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PhoneCards, StateBadge } from '@/components/content/list';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import type { CategoryChoiceData, SmartBarData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/* W2's smart bars (content.md §4.4, §1.9): Message · Shown On · State · Start · End, cards on a phone. */

const LIST = '/admin/content/bars';

function categoryPath(categories: CategoryChoiceData[], id: string | null, inLanguage: (ar: string | null, en: string | null) => string): string {
    const found = categories.find((each) => each.id === id);

    return found === undefined ? '' : inLanguage(found.pathAr, found.pathEn);
}

export function SmartBarsList({ rows, categories, onOpen, onAdd }: { rows: SmartBarData[]; categories: CategoryChoiceData[]; onOpen: (row: SmartBarData, from: HTMLElement | null) => void; onAdd: () => void }) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const [deleting, setDeleting] = useState<SmartBarData | null>(null);
    const [busy, setBusy] = useState(false);

    if (rows.length === 0) {
        return (
            <Empty className="material-base" data-test="smart-empty">
                <EmptyHeader>
                    <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_bars.smart.empty')}</EmptyTitle>
                    <EmptyDescription>{t('content::admin_bars.smart.empty_body')}</EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <Button type="button" onClick={onAdd}>
                        {t('content::admin_bars.smart.add')}
                    </Button>
                </EmptyContent>
            </Empty>
        );
    }

    const shownOn = (row: SmartBarData) => (row.scope === 'CATEGORY' ? categoryPath(categories, row.categoryId, inLanguage) : t('content::admin_bars.smart.all_lists'));
    const when = (iso: string | null) => (iso === null ? t('content::admin_shared.none') : <Time value={iso} mode="absolute" focusable={false} />);
    const menu = (row: SmartBarData) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton name={inLanguage(row.messageAr, row.messageEn)} data-test={`more-${row.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                <DropdownMenuItem onSelect={() => onOpen(row, null)} data-test="edit-smart-bar">
                    {t('content::admin_bars.smart.menu.edit')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(row)} data-test="delete-smart-bar">{`${t('content::admin_bars.smart.menu.delete')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <>
            <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                <Table>
                    <TableCaption className="sr-only">{t('content::admin_bars.tabs.smart')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t('content::admin_bars.smart.columns.message')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.shown_on')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.state')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.starts')}</TableHead>
                            <TableHead>{t('content::admin_bars.smart.columns.ends')}</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">{t('content::admin_shared.actions')}</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.id} className="cursor-pointer" onClick={(event) => onOpen(row, event.currentTarget)} data-test={`smart-${row.id}`}>
                                <TableCell className="max-w-72 truncate text-label-14 text-ink">{inLanguage(row.messageAr, row.messageEn)}</TableCell>
                                <TableCell className="max-w-64 truncate text-ink-muted">{shownOn(row)}</TableCell>
                                <TableCell>
                                    <StateBadge state={row.state} />
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-ink-muted">{when(row.startsAt)}</TableCell>
                                <TableCell className="whitespace-nowrap text-ink-muted">{when(row.endsAt)}</TableCell>
                                <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                                    {menu(row)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <PhoneCards
                test="smart"
                cards={rows.map((row) => ({
                    id: row.id,
                    title: inLanguage(row.messageAr, row.messageEn),
                    badge: <StateBadge state={row.state} />,
                    lines: [shownOn(row)],
                    menu: menu(row),
                    onOpen: (from) => onOpen(row, from),
                }))}
            />

            {deleting === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(open) => (open ? undefined : setDeleting(null))}
                    title={t('content::admin_bars.smart.delete.title')}
                    description={t('content::admin_bars.smart.delete.body')}
                    busy={busy}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(
                                    `${LIST}/smart/${deleting.id}/delete`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => {
                                            setBusy(false);
                                            setDeleting(null);
                                        },
                                    },
                                )
                            }
                            data-test="confirm-delete-smart-bar"
                        >
                            {t('content::admin_bars.smart.delete.confirm')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            )}
        </>
    );
}
