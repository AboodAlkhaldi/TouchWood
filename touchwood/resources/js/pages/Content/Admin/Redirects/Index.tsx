import { useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { PhoneCards } from '@/components/PhoneCards';
import { TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { MiddleTruncate } from '@/components/geist-only/MiddleTruncate';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { SearchField } from '@/components/SearchField';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useKeptDepth } from '@/lib/kept-depth';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import type { RedirectRowData, RedirectsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W11 · Redirects (content.md §4.4, §1.15): a search, then the lines, most visited first - old address (mono, cut in the
| middle), new address, visits, last visit, what Check Addresses found - each with Change…, Reset Count…, Use Final
| Address… and Delete Line…. Upload Sheet is the main action; Add Line… and Check Addresses ("Queued" while one waits)
| are in the page's menu. Super Admins alone.
*/

const LIST = '/admin/content/redirects';

type Asking = { kind: 'add' } | { kind: 'change'; row: RedirectRowData } | { kind: 'reset'; row: RedirectRowData } | { kind: 'delete'; row: RedirectRowData };

export default function Index({ rows, next, q, checkWaits }: RedirectsPageData) {
    const t = useTranslator();
    const [search, setSearch] = useState(q);
    const list = useLoadMore<RedirectRowData>(rows, (row) => row.oldPath);
    const more = (cursor: string) => list.more(LIST, { q, after: cursor }, ['rows', 'next']);
    const restoring = useKeptDepth(list, next, more);
    const [asking, setAsking] = useState<Asking | null>(null);
    const picker = useRef<HTMLInputElement>(null);
    const results: Record<string, string> = {
        FINE: t('content::admin_redirects.result.FINE'),
        FORWARDS: t('content::admin_redirects.result.FORWARDS'),
        NOT_FOUND: t('content::admin_redirects.result.NOT_FOUND'),
        STORE_OFF: t('content::admin_redirects.result.STORE_OFF'),
        CANT_BE_REACHED: t('content::admin_redirects.result.CANT_BE_REACHED'),
    };
    const check = (row: RedirectRowData) =>
        row.check === null ? (
            t('content::admin_shared.none')
        ) : (
            <Badge className={tone(row.check === 'FINE' ? 'green-subtle' : row.check === 'FORWARDS' ? 'blue-subtle' : 'amber-subtle')} title={row.finalPath === null ? undefined : t('content::admin_redirects.forwards_to', { path: row.finalPath })} data-test="check">
                {results[row.check] ?? row.check}
            </Badge>
        );
    const post = (action: string, row: RedirectRowData) => router.post(`${LIST}/${action}`, { line: row.oldPath }, { preserveScroll: true });
    const menu = (row: RedirectRowData) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton name={row.oldPath} data-test="more" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                <DropdownMenuItem onSelect={() => setAsking({ kind: 'change', row })} data-test="menu-change">{`${t('content::admin_redirects.menu.change')}…`}</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setAsking({ kind: 'reset', row })} data-test="menu-reset">{`${t('content::admin_redirects.menu.reset')}…`}</DropdownMenuItem>
                {row.check === 'FORWARDS' && row.finalPath !== null ? (
                    <DropdownMenuItem onSelect={() => post('final', row)} data-test="menu-final">
                        {t('content::admin_redirects.menu.final')}
                    </DropdownMenuItem>
                ) : null}
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => setAsking({ kind: 'delete', row })} data-test="menu-delete">{`${t('content::admin_redirects.menu.delete')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <AdminLayout
            title={t('content::admin_redirects.title')}
            subtitle={t('content::admin_redirects.subtitle')}
            action={
                <div className="flex items-center gap-2">
                    <Button type="button" onClick={() => picker.current?.click()} data-test="upload-sheet">
                        {t('content::admin_redirects.upload')}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton name={t('content::admin_redirects.menu.page_actions')} data-test="page-more" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-48">
                            <DropdownMenuItem onSelect={() => setAsking({ kind: 'add' })} data-test="menu-add">{`${t('content::admin_redirects.menu.add')}…`}</DropdownMenuItem>
                            <DropdownMenuItem disabled={checkWaits} onSelect={() => router.post(`${LIST}/check`, {}, { preserveScroll: true })} data-test="menu-check">
                                {checkWaits ? `${t('content::admin_redirects.menu.check')} · ${t('content::admin_redirects.check.waits')}` : t('content::admin_redirects.menu.check')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <input
                        ref={picker}
                        type="file"
                        className="sr-only"
                        accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        aria-label={t('content::admin_redirects.sheet.choose')}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';

                            if (file !== undefined) {
                                router.post(`${LIST}/sheets`, { sheet: file }, { forceFormData: true });
                            }
                        }}
                        data-test="sheet-file"
                    />
                </div>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <p className="text-copy-13 text-ink-muted">{t('content::admin_redirects.sheet.helper')}</p>
                <form
                    className="max-w-md"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(LIST, search.trim() === '' ? {} : { q: search.trim() });
                    }}
                    role="search"
                >
                    <SearchField id="q" label={t('content::admin_redirects.search')} placeholder={t('content::admin_redirects.search_placeholder')} value={search} onValueChange={setSearch} onClear={() => router.get(LIST)} data-test="search" />
                </form>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="redirects-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_redirects.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_redirects.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button type="button" onClick={() => picker.current?.click()}>
                                {t('content::admin_redirects.upload')}
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('content::admin_redirects.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_redirects.columns.old')}</TableHead>
                                        <TableHead>{t('content::admin_redirects.columns.new')}</TableHead>
                                        <TableHead className="text-end">{t('content::admin_redirects.columns.visits')}</TableHead>
                                        <TableHead>{t('content::admin_redirects.columns.last_visit')}</TableHead>
                                        <TableHead>{t('content::admin_redirects.columns.check')}</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('content::admin_shared.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.oldPath} className="cursor-pointer" onClick={() => setAsking({ kind: 'change', row })} data-test={`line-${row.oldPath}`}>
                                            <TableCell className="max-w-64" dir="ltr">
                                                <MiddleTruncate value={row.oldPath} className="font-mono text-copy-13" />
                                            </TableCell>
                                            <TableCell className="max-w-64" dir="ltr">
                                                <MiddleTruncate value={row.newPath} className="font-mono text-copy-13 text-ink-muted" />
                                            </TableCell>
                                            <TableCell className="tw-figure text-end">{figure('en', row.visits)}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{row.lastVisitAt === null ? t('content::admin_shared.none') : <Time value={row.lastVisitAt} focusable={false} />}</TableCell>
                                            <TableCell>{check(row)}</TableCell>
                                            <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                                                {menu(row)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="line"
                            cards={list.rows.map((row) => ({
                                id: row.oldPath,
                                title: <span dir="ltr" className="font-mono">{row.oldPath}</span>,
                                badge: row.check === null ? undefined : check(row),
                                lines: [<span key="new" dir="ltr" className="font-mono">{row.newPath}</span>, <span key="visits" className="tw-figure">{`${t('content::admin_redirects.columns.visits')}: ${row.visits}`}</span>],
                                menu: menu(row),
                                onOpen: () => setAsking({ kind: 'change', row }),
                            }))}
                        />
                        {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>

            {asking?.kind === 'add' || asking?.kind === 'change' ? <LineDialog row={asking.kind === 'change' ? asking.row : null} onClose={() => setAsking(null)} /> : null}
            {asking?.kind === 'reset' || asking?.kind === 'delete' ? <ConfirmDialog kind={asking.kind} row={asking.row} onClose={() => setAsking(null)} /> : null}
        </AdminLayout>
    );
}

function LineDialog({ row, onClose }: { row: RedirectRowData | null; onClose: () => void }) {
    const t = useTranslator();
    const form = useForm({ old_path: row?.oldPath ?? '', new_path: row?.newPath ?? '' });

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={row === null ? t('content::admin_redirects.line.add_title') : t('content::admin_redirects.line.change_title')}
            description={t('content::admin_redirects.line.body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    type="button"
                    loading={form.processing}
                    onClick={() => {
                        form.transform((data) => (row === null ? data : { ...data, line: row.oldPath }));
                        form.post(row === null ? LIST : `${LIST}/change`, { preserveScroll: true, onSuccess: onClose });
                    }}
                    data-test="save-line"
                >
                    {t('content::admin_redirects.line.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="old_path" dir="ltr" label={t('content::admin_redirects.line.old')} error={form.errors.old_path} value={form.data.old_path} onChange={(event) => form.setData('old_path', event.target.value)} data-test="old-path" />
                <TextField id="new_path" dir="ltr" label={t('content::admin_redirects.line.new')} error={form.errors.new_path} value={form.data.new_path} onChange={(event) => form.setData('new_path', event.target.value)} data-test="new-path" />
            </div>
        </PanelDialog>
    );
}

function ConfirmDialog({ kind, row, onClose }: { kind: 'reset' | 'delete'; row: RedirectRowData; onClose: () => void }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive={kind === 'delete'}
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={kind === 'reset' ? t('content::admin_redirects.reset.title') : t('content::admin_redirects.delete.title')}
            description={kind === 'reset' ? t('content::admin_redirects.reset.body') : t('content::admin_redirects.delete.body')}
            busy={busy}
            confirm={
                <ActionButton
                    variant={kind === 'delete' ? 'destructive' : 'default'}
                    loading={busy}
                    onClick={() => router.post(`${LIST}/${kind}`, { line: row.oldPath }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: onClose })}
                    data-test={`confirm-${kind}`}
                >
                    {kind === 'reset' ? t('content::admin_redirects.reset.confirm') : t('content::admin_redirects.delete.confirm')}
                </ActionButton>
            }
        >
            <p className="font-mono text-copy-13 break-all text-ink" dir="ltr">
                {row.oldPath}
            </p>
        </PanelDialog>
    );
}
