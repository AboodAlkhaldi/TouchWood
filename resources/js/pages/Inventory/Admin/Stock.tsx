import { cloneElement, lazy, Suspense, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { SearchField } from '@/components/SearchField';
import { SizeDrawer, useAddressDrawer } from '@/components/SizeDrawer';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { figure } from '@/lib/digits';
import { readJson, useDebounced } from '@/lib/live-read';
import { useLoadMore } from '@/lib/use-load-more';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { HistoryData, HistoryLineData, InventoryFrameData, SizeStockData, StockPage, StockRowData } from '@/types/generated/Modules/Inventory/Presentation/Http/Resource';
import { CountBox, useCounts } from './counts';
import type { AdjustTarget } from './dialogs';
import { InStock, LoadFailed, Mark, nameIn, otherName, plural, RowPhoto, Showing, sizeName, StockBadge, StoreFrame, threshold, useLocale, useT } from './parts';

// The dialogs load when one is first opened, not with the page.
const AdjustDialog = lazy(() => import('./dialogs').then((module) => ({ default: module.AdjustDialog })));
const ThresholdDialog = lazy(() => import('./dialogs').then((module) => ({ default: module.ThresholdDialog })));
const AddSizeDialog = lazy(() => import('./dialogs').then((module) => ({ default: module.AddSizeDialog })));

/*
| **Stock** (inventory.md §4.3 I1): the store's sizes switched on, and those with a stock row here, by product
| name then the size's place, 50 at a time with Show More. Each row: a selection checkbox, photo, product,
| size, code, **In Stock** - a box to count in (no provider; the provider's number, "From Provider" and when,
| in a wired store) -, Held, Available with its one badge, Threshold, wired: **Counts on Stock**; a ⋯ of View
| Stock (the drawer, in the address), Add or Remove Stock…, Set Threshold…, Show / Hide Last Pieces. The
| selection sets one threshold for many - and, wired, counts them on stock or not. **Add Size** stocks a size
| the store does not sell (Q24).
*/

const FILTERS = ['ALL', 'LOW', 'OUT_OF_STOCK', 'HELD', 'SHORT'] as const;

type Dialog = { kind: 'adjust'; size: AdjustTarget } | { kind: 'threshold'; sizes: string[]; name: string | null; current: number | null } | { kind: 'add' } | null;

export default function Stock(page: StockPage & { drawer: SizeStockData | null; history: HistoryData | null }) {
    const { storeFrame: frame, rows, next, total, search, filter } = page;
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState(search);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<StockRowData>(rows, (row) => row.size.variantId);
    const drawer = useAddressDrawer('size', ['drawer', 'history']);
    const countable = !frame.wired;
    const counts = useCounts(
        countable ? list.rows.filter((row) => !row.size.archived).map((row) => ({ id: row.size.variantId, inStock: row.figures.inStock, held: row.figures.held, holdingOrders: row.figures.holdingOrders })) : [],
        { store: frame.storeCode },
    );
    const filtered = search !== '' || filter !== 'ALL';
    const visit = (params: { q?: string; filter?: string }) => {
        const asked = { store: frame.storeCode, q: params.q ?? search, filter: params.filter ?? filter };
        // A filter or a search starts the selection again.
        setSelected([]);
        router.get('/admin/stock', Object.fromEntries(Object.entries(asked).filter(([key, value]) => value !== '' && !(key === 'filter' && value === 'ALL'))), { preserveState: true });
    };
    const typed = useDebounced(query, 300);

    useEffect(() => {
        if (typed !== search) {
            visit({ q: typed });
        }
        // Only a search that settled runs it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const nameOf = (row: { size: StockRowData['size'] }) => `${nameIn(locale, row.size.productNameAr, row.size.productNameEn)} · ${sizeName(t, row.size.values)}`;
    const shownIds = list.rows.map((row) => row.size.variantId);
    const allSelected = shownIds.length > 0 && shownIds.every((id) => selected.includes(id));
    const openDialog = (next: Dialog, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog(next);
    };
    const add = countable ? (
        <ActionButton type="button" onClick={(event) => openDialog({ kind: 'add' }, event.currentTarget)} data-test="add-size">
            {t('inventory::admin_stock.add_size.button')}
        </ActionButton>
    ) : undefined;
    const switchCounts = (sizes: string[], on: boolean) => router.post('/admin/stock/counts-on-stock', { store: frame.storeCode, sizes, on }, { preserveScroll: true });

    return (
        <AdminLayout title={t('inventory::admin_stock.title')} subtitle={t('inventory::admin_stock.subtitle')} action={add}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                        <SearchField id="stock-search" label={t('inventory::admin_stock.search')} placeholder={t('inventory::admin_stock.search_placeholder')} value={query} onValueChange={setQuery} className="max-w-sm" data-test="stock-search" />
                        <ToggleGroup
                            type="single"
                            value={filter}
                            onValueChange={(value) => (value === '' ? undefined : visit({ filter: value }))}
                            aria-label={t('inventory::admin_stock.filters.show')}
                            className={`${SWITCH_TRACK} flex-wrap`}
                            data-test="stock-filter"
                        >
                            {FILTERS.map((each) => (
                                <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM} data-test={`filter-${each}`}>
                                    {t(`inventory::admin_stock.filters.${each}`)}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setQuery('');
                                    setSelected([]);
                                    router.get('/admin/stock', { store: frame.storeCode }, { preserveState: true });
                                }}
                                data-test="clear-filters"
                            >
                                {t('inventory::admin_stock.clear_filters')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>

                {selected.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-3" data-test="selection">
                        <span className="tw-figure text-label-14 text-ink">{plural(t, locale, 'inventory::admin_stock.selected', selected.length)}</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={(event) => openDialog({ kind: 'threshold', sizes: selected, name: null, current: null }, event.currentTarget)}
                            data-test="threshold-selected"
                        >
                            {plural(t, locale, 'inventory::admin_stock.set_threshold_for', selected.length)}
                        </Button>
                        {frame.wired ? (
                            <>
                                <Button type="button" variant="outline" size="sm" onClick={() => switchCounts(selected, true)} data-test="count-on-selected">
                                    {t('inventory::admin_stock.count_on')}
                                </Button>
                                <Button type="button" variant="outline" size="sm" onClick={() => switchCounts(selected, false)} data-test="count-off-selected">
                                    {t('inventory::admin_stock.dont_count')}
                                </Button>
                            </>
                        ) : null}
                        <Button type="button" variant="ghost" size="sm" onClick={() => setSelected([])}>
                            {t('inventory::admin_stock.clear_selection')}
                        </Button>
                    </div>
                ) : null}

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="stock-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(filtered ? 'inventory::admin_stock.empty.filtered_title' : 'inventory::admin_stock.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(filtered ? 'inventory::admin_stock.empty.filtered_body' : 'inventory::admin_stock.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        {!filtered && add !== undefined ? <EmptyContent>{cloneElement(add, { 'data-test': 'add-size-empty' })}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('inventory::admin_stock.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <Checkbox
                                                checked={allSelected}
                                                onCheckedChange={(on) => setSelected(on === true ? shownIds : [])}
                                                aria-label={t('inventory::admin_stock.column.select_all')}
                                                data-test="select-all"
                                            />
                                        </TableHead>
                                        <TableHead className="w-14">
                                            <span className="sr-only">{t('inventory::admin_stock.column.photo')}</span>
                                        </TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.product')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.size')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.code')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.in_stock')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.held')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.available')}</TableHead>
                                        <TableHead>{t('inventory::admin_stock.column.threshold')}</TableHead>
                                        {frame.wired ? <TableHead>{t('inventory::admin_stock.column.counts_on_stock')}</TableHead> : null}
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('inventory::admin_stock.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => {
                                        const id = row.size.variantId;
                                        const countRow = { id, inStock: row.figures.inStock, held: row.figures.held, holdingOrders: row.figures.holdingOrders };

                                        return (
                                            <TableRow key={id} data-test={`stock-row-${id}`}>
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selected.includes(id)}
                                                        onCheckedChange={(on) => setSelected((before) => (on === true ? [...before, id] : before.filter((each) => each !== id)))}
                                                        aria-label={t('inventory::admin_stock.column.select', { size: nameOf(row) })}
                                                        data-test={`select-${id}`}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <RowPhoto src={row.size.photo} />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="grid">
                                                        <Link
                                                            href={`/admin/stock?store=${frame.storeCode}&size=${id}`}
                                                            onClick={(event) => {
                                                                event.preventDefault();
                                                                opener.current = event.currentTarget as HTMLElement;
                                                                drawer.open(id);
                                                            }}
                                                            className="text-label-14 text-ink hover:underline"
                                                            data-test={`open-size-${id}`}
                                                        >
                                                            {nameIn(locale, row.size.productNameAr, row.size.productNameEn)}
                                                        </Link>
                                                        {otherName(locale, row.size.productNameAr, row.size.productNameEn) ? (
                                                            <span className="text-copy-12 text-ink-muted" dir={locale === 'ar' ? 'ltr' : 'rtl'}>
                                                                {otherName(locale, row.size.productNameAr, row.size.productNameEn)}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        {sizeName(t, row.size.values)}
                                                        {!row.size.switchedOn ? <Mark tone="gray-subtle">{t('inventory::admin_stock.badge.not_switched_on')}</Mark> : null}
                                                        {row.size.archived ? <Mark tone="amber-subtle" test="archived">{t('inventory::admin_stock.badge.archived')}</Mark> : null}
                                                        {row.lastPieces === true ? <Mark tone="blue-subtle">{t('inventory::admin_stock.badge.last_pieces')}</Mark> : null}
                                                    </span>
                                                    {row.size.archived ? <span className="mt-1 block max-w-56 text-copy-12 text-ink-muted">{frame.reasons.archived}</span> : null}
                                                </TableCell>
                                                <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                                    {row.size.code}
                                                </TableCell>
                                                <TableCell className="align-top" data-test="in-stock">
                                                    {countable && !row.size.archived ? (
                                                        <CountBox
                                                            id={counts.idOf(id)}
                                                            label={t('inventory::admin_stock.box.label', { size: nameOf(row) })}
                                                            value={counts.valueOf(countRow)}
                                                            onChange={(value) => counts.type(id, value)}
                                                            onEscape={() => counts.reset(id)}
                                                            onEnter={counts.save}
                                                            changed={counts.changed(id)}
                                                            error={counts.error(id)}
                                                            warning={counts.warning(countRow)}
                                                        />
                                                    ) : (
                                                        <InStock figures={row.figures} wired={frame.wired} />
                                                    )}
                                                </TableCell>
                                                <TableCell className="tw-figure align-top">{figure(locale, row.figures.held)}</TableCell>
                                                <TableCell className="align-top" data-test="available">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        <span className="tw-figure">{figure(locale, row.figures.available)}</span>
                                                        <StockBadge figures={row.figures} />
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tw-figure align-top">{threshold(t, locale, row.figures)}</TableCell>
                                                {frame.wired ? (
                                                    <TableCell className="align-top">
                                                        <Switch
                                                            checked={row.figures.stockDependent}
                                                            onCheckedChange={(on) => switchCounts([id], on)}
                                                            aria-label={t('inventory::admin_stock.counts_on_label', { size: nameOf(row) })}
                                                            data-test={`counts-on-${id}`}
                                                        />
                                                    </TableCell>
                                                ) : null}
                                                <TableCell className="text-end align-top">
                                                    <RowMenu
                                                        row={row}
                                                        frame={frame}
                                                        name={nameOf(row)}
                                                        onView={(trigger) => {
                                                            opener.current = trigger;
                                                            drawer.open(id);
                                                        }}
                                                        onAdjust={(trigger) => openDialog({ kind: 'adjust', size: { variantId: id, name: nameOf(row), figures: row.figures } }, trigger)}
                                                        onThreshold={(trigger) => openDialog({ kind: 'threshold', sizes: [id], name: nameOf(row), current: row.figures.ownThreshold }, trigger)}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {next !== null ? (
                    <LoadMoreButton
                        loading={list.loading}
                        onClick={() => list.more('/admin/stock', Object.fromEntries(Object.entries({ store: frame.storeCode, q: search, filter: filter === 'ALL' ? '' : filter, after: next }).filter(([, value]) => value !== '')), ['rows', 'next', 'total'])}
                    />
                ) : null}

                {countable ? counts.bar() : null}
            </div>

            <SizeDrawer
                open={drawer.value !== null}
                onClose={drawer.close}
                title={page.drawer === null ? '' : nameIn(locale, page.drawer.size.productNameAr, page.drawer.size.productNameEn)}
                description={page.drawer === null ? '' : `${sizeName(t, page.drawer.size.values)} · ${page.drawer.size.code}`}
                loading={drawer.loading || page.drawer === null || page.drawer.size.variantId !== drawer.value}
                returnFocusTo={opener}
            >
                {page.drawer !== null ? (
                    <DrawerBody
                        view={page.drawer}
                        history={page.history}
                        frame={frame}
                        onAdjust={(trigger) => page.drawer !== null && openDialog({ kind: 'adjust', size: { variantId: page.drawer.size.variantId, name: nameOf(page.drawer), figures: page.drawer.figures } }, trigger)}
                        onThreshold={(trigger) => page.drawer !== null && openDialog({ kind: 'threshold', sizes: [page.drawer.size.variantId], name: nameOf(page.drawer), current: page.drawer.figures.ownThreshold }, trigger)}
                    />
                ) : null}
            </SizeDrawer>

            <Suspense fallback={null}>
                {dialog?.kind === 'adjust' ? <AdjustDialog store={frame.storeCode} size={dialog.size} onOpenChange={(open) => (open ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
                {dialog?.kind === 'threshold' ? (
                    <ThresholdDialog
                        store={frame.storeCode}
                        sizes={dialog.sizes}
                        name={dialog.name}
                        current={dialog.current}
                        defaultThreshold={frame.defaultThreshold}
                        onSaved={() => setSelected([])}
                        onOpenChange={(open) => (open ? undefined : setDialog(null))}
                        returnFocusTo={opener}
                    />
                ) : null}
                {dialog?.kind === 'add' ? <AddSizeDialog store={frame.storeCode} onOpenChange={(open) => (open ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
            </Suspense>
        </AdminLayout>
    );
}


function RowMenu({
    row,
    frame,
    name,
    onView,
    onAdjust,
    onThreshold,
}: {
    row: StockRowData;
    frame: InventoryFrameData;
    name: string;
    onView: (trigger: HTMLElement | null) => void;
    onAdjust: (trigger: HTMLElement | null) => void;
    onThreshold: (trigger: HTMLElement | null) => void;
}) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);
    const archived = row.size.archived ? frame.reasons.archived : null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={`stock-actions-${row.size.variantId}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                <Guarded label={t('inventory::admin_stock.menu.view')} onSelect={() => onView(more.current)} test="view-stock" />
                {/* A wired store's stock is the provider's (§1.5): Add or Remove not offered. */}
                {frame.wired ? null : <Guarded label={t('inventory::admin_stock.menu.adjust')} reason={archived} onSelect={() => onAdjust(more.current)} test="adjust-stock" />}
                <Guarded label={t('inventory::admin_stock.menu.threshold')} reason={archived} onSelect={() => onThreshold(more.current)} test="set-threshold" />
                {row.lastPieces === null || frame.wired ? null : (
                    <LastPiecesItem store={frame.storeCode} product={row.size.productId} on={row.lastPieces} />
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Show / Hide Last Pieces for the row's product, with what it does under it (no provider). */
function LastPiecesItem({ store, product, on }: { store: string; product: string; on: boolean }) {
    const t = useT();

    return (
        <DropdownMenuItem onSelect={() => router.post('/admin/stock/last-pieces', { store, product, on: !on }, { preserveScroll: true })} data-test="last-pieces">
            <span className="grid gap-0.5">
                <span>{on ? t('inventory::admin_stock.menu.hide_last') : t('inventory::admin_stock.menu.show_last')}</span>
                <span className="text-copy-12 text-ink-muted">{t('inventory::admin_stock.menu.last_helper')}</span>
            </span>
        </DropdownMenuItem>
    );
}

/** The drawer's body (Q3): figures and badge, holds (Q21), Last Pieces, history (Q22) with Show More, and its own actions. */
function DrawerBody({ view, history, frame, onAdjust, onThreshold }: { view: SizeStockData; history: HistoryData | null; frame: InventoryFrameData; onAdjust: (trigger: HTMLElement | null) => void; onThreshold: (trigger: HTMLElement | null) => void }) {
    const t = useT();
    const locale = useLocale();
    const { figures, holds } = view;
    const archived = view.size.archived;

    return (
        <div className="grid gap-6" data-test="drawer-body">
            <section className="grid gap-2" aria-labelledby="drawer-figures">
                <h3 id="drawer-figures" className="text-heading-14 text-ink">
                    {t('inventory::admin_stock.drawer.figures')}
                </h3>
                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-copy-14">
                    <dt className="text-ink-muted">{t('inventory::admin_stock.column.in_stock')}</dt>
                    <dd>
                        <InStock figures={figures} wired={frame.wired} />
                    </dd>
                    <dt className="text-ink-muted">{t('inventory::admin_stock.column.held')}</dt>
                    <dd className="tw-figure">{figure(locale, figures.held)}</dd>
                    <dt className="text-ink-muted">{t('inventory::admin_stock.column.available')}</dt>
                    <dd>
                        <span className="flex flex-wrap items-center gap-2">
                            <span className="tw-figure">{figure(locale, figures.available)}</span>
                            <StockBadge figures={figures} />
                        </span>
                    </dd>
                    <dt className="text-ink-muted">{t('inventory::admin_stock.column.threshold')}</dt>
                    <dd className="tw-figure">{threshold(t, locale, figures)}</dd>
                    {view.lastPieces !== null ? (
                        <>
                            <dt className="text-ink-muted">{t('inventory::admin_stock.drawer.last_pieces')}</dt>
                            <dd>{view.lastPieces ? t('inventory::admin_stock.drawer.shown') : t('inventory::admin_stock.drawer.hidden')}</dd>
                        </>
                    ) : null}
                </dl>
                <div className="flex flex-wrap gap-2">
                    {frame.wired ? null : (
                        <ActionButton type="button" variant="outline" size="sm" disabledReason={archived ? frame.reasons.archived : undefined} onClick={(event) => onAdjust(event.currentTarget)} data-test="drawer-adjust">
                            {t('inventory::admin_stock.menu.adjust')}
                        </ActionButton>
                    )}
                    <ActionButton type="button" variant="outline" size="sm" disabledReason={archived ? frame.reasons.archived : undefined} onClick={(event) => onThreshold(event.currentTarget)} data-test="drawer-threshold">
                        {t('inventory::admin_stock.menu.threshold')}
                    </ActionButton>
                </div>
            </section>

            <section className="grid gap-2" aria-labelledby="drawer-holds">
                <h3 id="drawer-holds" className="text-heading-14 text-ink">
                    {t('inventory::admin_stock.drawer.holds')}
                </h3>
                <p className="tw-figure text-copy-14 text-ink" data-test="drawer-holds">
                    {holds.orders === 0
                        ? t('inventory::admin_stock.drawer.no_holds')
                        : plural(t, locale, 'inventory::admin_stock.drawer.holds_line', holds.orders, { pieces: figure(locale, holds.pieces), bought: figure(locale, holds.bought), gift: figure(locale, holds.gift) })}
                </p>
            </section>

            <History store={frame.storeCode} size={view.size.variantId} first={history} />
        </div>
    );
}

/** A size's history (Q22), newest first, 50 at a time; a page asked for an older drawer is dropped. */
function History({ store, size, first }: { store: string; size: string; first: HistoryData | null }) {
    const t = useT();
    const [lines, setLines] = useState<HistoryLineData[]>(first?.lines ?? []);
    const [cursor, setCursor] = useState<string | null>(first?.next ?? null);
    const [fetching, setFetching] = useState(false);
    const [failed, setFailed] = useState(false);
    const generation = useRef(0);

    useEffect(() => {
        generation.current += 1;
        setLines(first?.lines ?? []);
        setCursor(first?.next ?? null);
        setFetching(false);
        setFailed(false);
    }, [first, size]);

    const more = () => {
        if (cursor === null) {
            return;
        }

        const asked = generation.current;
        setFetching(true);
        setFailed(false);
        readJson<HistoryData>('/admin/stock/history', { store, size, after: cursor }).then((page) => {
            if (asked !== generation.current) {
                return;
            }

            setFetching(false);

            if (page === null) {
                setFailed(true);

                return;
            }

            setLines((before) => [...before, ...page.lines]);
            setCursor(page.next);
        });
    };

    return (
        <section className="grid gap-3" aria-labelledby="drawer-history">
            <h3 id="drawer-history" className="text-heading-14 text-ink">
                {t('inventory::admin_stock.drawer.history')}
            </h3>
            {first === null ? null : lines.length === 0 ? <p className="text-copy-14 text-ink-muted">{t('inventory::admin_stock.drawer.no_history')}</p> : null}
            <ol className="grid gap-3" data-test="drawer-history">
                {lines.map((line, index) => (
                    <HistoryItem key={`${line.at}-${index}`} line={line} />
                ))}
            </ol>
            {failed ? <LoadFailed what="history" onRetry={more} /> : null}
            {cursor !== null && !failed ? <LoadMoreButton loading={fetching} onClick={more} /> : null}
        </section>
    );
}

function HistoryItem({ line }: { line: HistoryLineData }) {
    const t = useT();
    const locale = useLocale();
    const change = `${line.change > 0 ? '+' : line.change < 0 ? '−' : ''}${figure(locale, Math.abs(line.change))}`;
    const who: ReactNode =
        line.who === 'STAFF'
            ? line.staffHidden || line.staffName === null
                ? t('inventory::admin_stock.drawer.who.staff_hidden')
                : line.staffName
            : line.who === 'FILE' && line.staffName !== null && !line.staffHidden
              ? t('inventory::admin_stock.drawer.who.FILE_named', { name: line.staffName })
              : t(`inventory::admin_stock.drawer.who.${line.who}`);

    return (
        <li className="grid gap-0.5 text-copy-14" data-test="history-line">
            <span className="flex flex-wrap items-center gap-2">
                <span className="text-ink">{t(`inventory::admin_stock.reason.${line.reason}`)}</span>
                <bdi dir="ltr" className="tw-figure text-ink">
                    {change}
                </bdi>
                <span className="tw-figure inline-flex items-center gap-1 text-copy-13 text-ink-muted">
                    {figure(locale, line.before)}
                    <ArrowRight aria-hidden="true" className="size-3.5 rtl:rotate-180" />
                    <span className="sr-only">{t('inventory::admin_stock.to')}</span>
                    {figure(locale, line.after)}
                </span>
            </span>
            <span className="text-copy-12 text-ink-muted">
                {who} · <Time value={line.at} inSentence />
            </span>
            {line.note !== null ? <span className="text-copy-13 text-ink">{line.note}</span> : null}
        </li>
    );
}
