import { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { ImportPage, ImportProductData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { ConfirmByName, ProductStateBadge, SelectAll, useSelection } from './parts';
import { ImportShell } from './shell';

/*
| An import's products (catalog.md §4.4 S11 part 4, §1.12; amendments 9(b), 11(a), 11(c)): every
| product of the file, a hundred at a time (P13), with its state - Waiting · Left Out with why · In ·
| Updated · Replaced · Skipped · Held Back · Accepted · Archived · Deleted - and, brought in, what it
| still lacks, its name a link to its page. Ticked rows, once brought in: **Accept** (the ready ones:
| made ready, on sale nowhere), **Archive…**, **Delete…** - the dialogs saying the Super Admin's word
| is carried out over anything done since, one on sale switched off first, Delete asking for the
| file's name (P10) -; and **Accept Every Ready One**.
*/

/** The states of a product brought in, which these actions reach. */
const BROUGHT_IN = ['IN', 'UPDATED', 'REPLACED', 'ACCEPTED'];

function Products({ page }: { page: ImportPage }) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useLoadMore<ImportProductData>(page.products ?? [], (row) => row.id);
    const isIn = page.import.state === 'IN';
    const actionable = isIn ? list.rows.filter((row) => row.productId !== null && BROUGHT_IN.includes(row.state)) : [];
    const selection = useSelection(actionable.map((row) => row.id));
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<'archive' | 'delete' | null>(null);
    const opener = useRef<HTMLElement | null>(null);
    const ready = actionable.some((row) => row.state !== 'ACCEPTED' && row.missing.length === 0);
    const none = selection.chosen.length === 0 ? t('catalog::admin_imports.none_selected') : undefined;
    const notIn = isIn ? undefined : t('catalog::admin_imports.products.not_in_yet');
    const send = (action: 'accept' | 'archive' | 'delete', productIds: string[] | null, onSuccess?: () => void) =>
        router.post(`/admin/imports/${page.import.id}/${action}`, productIds === null ? {} : { product_ids: productIds }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                selection.clear();
                onSuccess?.();
            },
        });
    const open = (next: 'archive' | 'delete', trigger: HTMLElement) => {
        opener.current = trigger;
        setDialog(next);
    };

    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <ActionButton type="button" loading={busy} disabledReason={notIn ?? (ready ? undefined : t('catalog::admin_imports.products.ready_none'))} onClick={() => send('accept', null)} data-test="accept-ready">
                    {t('catalog::admin_imports.products.accept_ready')}
                </ActionButton>
                <ActionButton type="button" variant="secondary" loading={busy} disabledReason={notIn ?? none} onClick={() => send('accept', selection.chosen)} data-test="accept-selected">
                    {t('catalog::admin_imports.products.accept')}
                </ActionButton>
                <ActionButton type="button" variant="outline" disabledReason={notIn ?? none} onClick={(event) => open('archive', event.currentTarget)} data-test="archive-selected">
                    {t('catalog::admin_imports.products.archive')}
                </ActionButton>
                <ActionButton type="button" variant="outline" disabledReason={notIn ?? none} onClick={(event) => open('delete', event.currentTarget)} data-test="delete-selected">
                    {t('catalog::admin_imports.products.delete')}
                </ActionButton>
            </div>

            <div className="material-base overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t('catalog::admin_imports.part.products')}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            {isIn ? (
                                <TableHead className="w-10">
                                    <SelectAll selection={selection} offered={actionable.length} disabled={busy} />
                                </TableHead>
                            ) : null}
                            <TableHead className="text-end">{t('catalog::admin_imports.products.column.number')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.products.column.product')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.products.column.codes')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.products.column.state')}</TableHead>
                            <TableHead>{t('catalog::admin_imports.products.column.lacks')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {list.rows.map((row) => {
                            const name = nameIn(locale, row.nameAr, row.nameEn);
                            const reachable = actionable.some((other) => other.id === row.id);

                            return (
                                <TableRow key={row.id} data-test={`product-row-${row.number}`} data-state={row.state}>
                                    {isIn ? (
                                        <TableCell>
                                            {reachable ? <Checkbox checked={selection.has(row.id)} disabled={busy} onCheckedChange={(on) => selection.set(row.id, on === true)} aria-label={t('catalog::admin_imports.select_row', { name })} /> : null}
                                        </TableCell>
                                    ) : null}
                                    <TableCell className="tw-figure text-end text-ink-muted">{figure(locale, row.number)}</TableCell>
                                    <TableCell>
                                        {row.productId !== null && row.state !== 'DELETED' ? (
                                            <Link href={`/admin/products/${row.productId}`} className="text-label-14 text-ink underline-offset-4 hover:underline">
                                                <bdi>{name}</bdi>
                                            </Link>
                                        ) : (
                                            <bdi className="text-label-14 text-ink">{name}</bdi>
                                        )}
                                    </TableCell>
                                    <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                        {row.codes.join(' · ')}
                                    </TableCell>
                                    <TableCell>
                                        <span className="grid gap-1">
                                            <ProductStateBadge state={row.state} />
                                            {row.state === 'REFUSED' && row.refusal !== null ? (
                                                <span className="text-copy-13 text-ink-muted" dir="ltr">
                                                    {row.refusal}
                                                </span>
                                            ) : null}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-copy-13 text-ink-muted">{row.missing.length === 0 ? '—' : row.missing.map((rule) => t(`catalog::admin_products.missing.${rule}`)).join(locale === 'ar' ? '، ' : ', ')}</TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            {page.more ? <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { part: 'products', page: page.page + 1 }, ['products', 'page', 'more'])} /> : null}

            {dialog === 'archive' ? (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(next) => (next ? undefined : setDialog(null))}
                    returnFocusTo={opener}
                    title={t('catalog::admin_imports.products.archive_title')}
                    description={t('catalog::admin_imports.products.archive_body')}
                    busy={busy}
                    confirm={
                        <ActionButton variant="destructive" loading={busy} onClick={() => send('archive', selection.chosen, () => setDialog(null))} data-test="confirm-archive">
                            {t('catalog::admin_imports.products.archive_confirm')}
                        </ActionButton>
                    }
                >
                    <p className="tw-figure text-copy-14 text-ink">{t('catalog::admin_imports.selected', { count: figure(locale, selection.chosen.length) })}</p>
                </PanelDialog>
            ) : null}
            {dialog === 'delete' ? (
                <ConfirmByName
                    fileName={page.import.fileName}
                    title={t('catalog::admin_imports.products.delete_title')}
                    body={t('catalog::admin_imports.products.delete_body')}
                    confirm={t('catalog::admin_imports.products.delete_confirm')}
                    busy={busy}
                    onConfirm={() => send('delete', selection.chosen, () => setDialog(null))}
                    onOpenChange={(next) => (next ? undefined : setDialog(null))}
                    returnFocusTo={opener}
                    testId="confirm-delete"
                >
                    <p className="tw-figure text-copy-14 text-ink">{t('catalog::admin_imports.selected', { count: figure(locale, selection.chosen.length) })}</p>
                </ConfirmByName>
            ) : null}
        </div>
    );
}

/** The products part's page: the import above its parts, this one open (ImportShell). */
export default function ProductsPart(page: ImportPage) {
    return (
        <ImportShell page={page}>
            <Products page={page} />
        </ImportShell>
    );
}
