import { lazy, Suspense, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PhoneCards } from '@/components/PhoneCards';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useKeptDepth } from '@/lib/kept-depth';
import { useLoadMore } from '@/lib/use-load-more';
import type { LowStockPage, StockRowData } from '@/types/generated/Modules/Inventory/Presentation/Http/Resource';
import type { AdjustTarget } from './dialogs';
import { nameIn, plural, RowPhoto, Showing, sizeName, StockBadge, StoreFrame, threshold, useLocale, useT } from './parts';

// The dialogs load when one is first opened, not with the page.
const AdjustDialog = lazy(() => import('./dialogs').then((module) => ({ default: module.AdjustDialog })));
const ThresholdDialog = lazy(() => import('./dialogs').then((module) => ({ default: module.ThresholdDialog })));

/*
| **Low Stock** (inventory.md §4.3 I2): the sizes at or below their threshold, the fewest left first, 50 at a
| time; the subtitle says how many in which store. The product's name opens the size in Stock's drawer; a
| row's ⋯ is Add or Remove Stock… (no provider) and Set Threshold…. Archived sizes are never listed.
*/

type Dialog = { kind: 'adjust'; size: AdjustTarget } | { kind: 'threshold'; size: string; name: string; current: number | null } | null;

export default function LowStock(page: LowStockPage) {
    const { storeFrame: frame, rows, next, total } = page;
    const t = useT();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<StockRowData>(rows, (row) => row.size.variantId);
    const more = (cursor: string) => list.more('/admin/low-stock', { store: frame.storeCode, after: cursor }, ['rows', 'next', 'total']);
    // A change keeps the rows Show More had shown.
    const restoring = useKeptDepth(list, next, more);
    const nameOf = (row: StockRowData) => `${nameIn(locale, row.size.productNameAr, row.size.productNameEn)} · ${sizeName(t, row.size.values)}`;
    const subtitle = `${t('inventory::admin_low.subtitle')} ${plural(t, locale, 'inventory::admin_low.count_in', total, { store: frame.storeName })}`;
    const wired = frame.wired ? frame.reasons.wired : null;
    // A row's two dialogs, opened from its ⋯ in the table or on its phone card.
    const onAdjust = (row: StockRowData) => (trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog({ kind: 'adjust', size: { variantId: row.size.variantId, name: nameOf(row), figures: row.figures } });
    };
    const onThreshold = (row: StockRowData) => (trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog({ kind: 'threshold', size: row.size.variantId, name: nameOf(row), current: row.figures.ownThreshold });
    };

    return (
        <AdminLayout title={t('inventory::admin_low.title')} subtitle={subtitle}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="low-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('inventory::admin_low.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('inventory::admin_low.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base hidden overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('inventory::admin_low.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-14">
                                            <span className="sr-only">{t('inventory::admin_stock.column.photo')}</span>
                                        </TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.product')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.size')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.code')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.available')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.threshold')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('inventory::admin_stock.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <LowRow key={row.size.variantId} row={row} store={frame.storeCode} wired={wired} name={nameOf(row)} onAdjust={onAdjust(row)} onThreshold={onThreshold(row)} />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {/* On a phone each size a card (round 3 #15): its name opens it on Stock, as the row's. */}
                        <PhoneCards
                            test="low-row"
                            cards={list.rows.map((row) => ({
                                id: row.size.variantId,
                                title: nameIn(locale, row.size.productNameAr, row.size.productNameEn),
                                href: `/admin/stock?store=${frame.storeCode}&size=${row.size.variantId}`,
                                badge: <StockBadge figures={row.figures} />,
                                lines: [
                                    <span key="s">
                                        {`${t('inventory::admin_stock.column.size')}: ${sizeName(t, row.size.values)} · `}
                                        <bdi dir="ltr" className="tw-figure font-mono">
                                            {row.size.code}
                                        </bdi>
                                    </span>,
                                    <span key="f" className="tw-figure">{`${t('inventory::admin_stock.column.available')}: ${figure(locale, row.figures.available)} · ${t('inventory::admin_stock.column.threshold')}: ${threshold(t, locale, row.figures)}`}</span>,
                                ],
                                menu: <LowMenu name={nameOf(row)} wired={wired} onAdjust={onAdjust(row)} onThreshold={onThreshold(row)} test={`low-card-actions-${row.size.variantId}`} />,
                            }))}
                        />
                    </>
                )}

                {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
            </div>

            <Suspense fallback={null}>
                {dialog?.kind === 'adjust' ? <AdjustDialog store={frame.storeCode} size={dialog.size} onOpenChange={(open) => (open ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
                {dialog?.kind === 'threshold' ? (
                    <ThresholdDialog
                        store={frame.storeCode}
                        sizes={[dialog.size]}
                        name={dialog.name}
                        current={dialog.current}
                        defaultThreshold={frame.defaultThreshold}
                        onOpenChange={(open) => (open ? undefined : setDialog(null))}
                        returnFocusTo={opener}
                    />
                ) : null}
            </Suspense>
        </AdminLayout>
    );
}

function LowRow({
    row,
    store,
    wired,
    name,
    onAdjust,
    onThreshold,
}: {
    row: StockRowData;
    store: string;
    /** Why Add or Remove Stock is out of reach in a wired store, or null. */
    wired: string | null;
    name: string;
    onAdjust: (trigger: HTMLElement | null) => void;
    onThreshold: (trigger: HTMLElement | null) => void;
}) {
    const t = useT();
    const locale = useLocale();
    const id = row.size.variantId;

    return (
        <TableRow data-test={`low-row-${id}`}>
            <TableCell>
                <RowPhoto src={row.size.photo} />
            </TableCell>
            <TableCell>
                {/* The product's name opens the size in Stock's drawer (I2). */}
                <Link href={`/admin/stock?store=${store}&size=${id}`} className="text-label-14 text-ink hover:underline" data-test={`open-size-${id}`}>
                    {nameIn(locale, row.size.productNameAr, row.size.productNameEn)}
                </Link>
            </TableCell>
            <TableCell>{sizeName(t, row.size.values)}</TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {row.size.code}
            </TableCell>
            <TableCell>
                <span className="flex flex-wrap items-center gap-2">
                    <span className="tw-figure">{figure(locale, row.figures.available)}</span>
                    <StockBadge figures={row.figures} />
                </span>
            </TableCell>
            <TableCell className="tw-figure">{threshold(t, locale, row.figures)}</TableCell>
            <TableCell className="text-end">
                <LowMenu name={name} wired={wired} onAdjust={onAdjust} onThreshold={onThreshold} test={`low-actions-${id}`} />
            </TableCell>
        </TableRow>
    );
}

/** A low size's ⋯ menu: the row's, and the same on its phone card under its own test name. */
function LowMenu({
    name,
    wired,
    onAdjust,
    onThreshold,
    test,
}: {
    name: string;
    wired: string | null;
    onAdjust: (trigger: HTMLElement | null) => void;
    onThreshold: (trigger: HTMLElement | null) => void;
    test: string;
}) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={test} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                {/* A wired store's stock is the provider's (§1.5): drawn, with why not. */}
                <Guarded label={t('inventory::admin_stock.menu.adjust')} reason={wired} onSelect={() => onAdjust(more.current)} test="adjust-stock" />
                <Guarded label={t('inventory::admin_stock.menu.threshold')} onSelect={() => onThreshold(more.current)} test="set-threshold" />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
