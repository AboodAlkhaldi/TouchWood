import { cloneElement, lazy, Suspense, useEffect, useRef, useState, type RefObject } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { figure } from '@/lib/digits';
import { useLoadMore } from '@/lib/use-load-more';
import type { PricingFrameData, SizeData, SizeResultsData, WholesalePage, WholesaleRowData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import type { BandsFacts } from './BandsDialog';
import { Guarded, ltr, Mark, MoreButton, nameIn, RowPhoto, Showing, sizeName, StoreFrame, useDebounced, useLocale, useT } from './parts';
import { RemoteCombobox } from './picker';

// The bands dialog loads when it is first opened, not with the page.
const BandsDialog = lazy(() => import('./BandsDialog').then((module) => ({ default: module.BandsDialog })));

/*
| **Wholesale Prices** (pricing.md §4.3.3 P4): the sizes with bands in the store, each with its retail price,
| its bands ("From 10: 95.00 · From 50: 90.00") and the wholesale minimum, and its marks - Kept, Not Sold
| Wholesale (§1.4: kept unused); No Retail Price (the bands wait for one); Minimum Changed (the first band no
| longer starts at it). **Add Wholesale Prices**: a size the store sells wholesale with no bands yet, then
| its bands. A row's ⋯: Edit Wholesale Prices…, Remove Wholesale Prices….
*/

type Dialog = { kind: 'bands' | 'remove' | 'pick'; size: { variantId: string; name: string } | null; facts: BandsFacts | null; adding: boolean } | null;

export default function WholesalePrices(page: WholesalePage) {
    const { storeFrame: frame, rows, next, total, search } = page;
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState(search);
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<WholesaleRowData>(rows, (row) => row.size.variantId);
    const typed = useDebounced(query, 300);
    const store = { code: frame.storeCode, decimals: frame.decimals, currency: frame.currency };
    const close = (open: boolean) => (open ? undefined : setDialog(null));

    useEffect(() => {
        if (typed !== search) {
            router.get('/admin/wholesale-prices', typed === '' ? { store: frame.storeCode } : { store: frame.storeCode, q: typed }, { preserveState: true });
        }
        // Only a search that settled runs it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const add = frame.mayEditWholesale ? (
        <ActionButton
            type="button"
            onClick={(event) => {
                opener.current = event.currentTarget;
                setDialog({ kind: 'pick', size: null, facts: null, adding: true });
            }}
            data-test="add-wholesale"
        >
            {t('pricing::admin_wholesale.add')}
        </ActionButton>
    ) : undefined;

    return (
        <AdminLayout title={t('pricing::admin_wholesale.title')} subtitle={t('pricing::admin_wholesale.subtitle')} action={add}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                        <SearchField id="wholesale-search" label={t('pricing::admin_prices.filters.search')} placeholder={t('pricing::admin.search_sizes')} value={query} onValueChange={setQuery} className="max-w-sm" data-test="wholesale-search" />
                        {search !== '' ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setQuery('');
                                    router.get('/admin/wholesale-prices', { store: frame.storeCode }, { preserveState: true });
                                }}
                            >
                                {t('pricing::admin.clear_filters')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="wholesale-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(search !== '' ? 'pricing::admin_wholesale.empty.filtered_title' : 'pricing::admin_wholesale.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(search !== '' ? 'pricing::admin_wholesale.empty.filtered_body' : 'pricing::admin_wholesale.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        {search === '' && add !== undefined ? <EmptyContent>{cloneElement(add, { 'data-test': 'add-wholesale-empty' })}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('pricing::admin_wholesale.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-14">
                                            <span className="sr-only">{t('pricing::admin.column.photo')}</span>
                                        </TableHead>
                                        <TableHead>{t('pricing::admin.column.product')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.size')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.code')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.retail_price')}</TableHead>
                                        <TableHead>{t('pricing::admin_wholesale.column.bands')}</TableHead>
                                        <TableHead>{t('pricing::admin_wholesale.column.minimum')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('pricing::admin.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <Row
                                            key={row.size.variantId}
                                            row={row}
                                            frame={frame}
                                            onOpen={(kind, trigger) => {
                                                opener.current = trigger;
                                                setDialog({
                                                    kind,
                                                    size: { variantId: row.size.variantId, name: sizeName(t, row.size.values) },
                                                    facts: { retail: row.retail?.price ?? null, minimum: row.minimum, maximum: row.maximum, bands: row.bands, minimumChanged: row.minimumChanged },
                                                    adding: false,
                                                });
                                            }}
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/wholesale-prices', { store: frame.storeCode, ...(search === '' ? {} : { q: search }), after: next }, ['rows', 'next', 'total'])} /> : null}
            </div>

            {dialog?.kind === 'pick' ? (
                <PickSize
                    frame={frame}
                    onOpenChange={close}
                    returnFocusTo={opener}
                    onChoose={(size) => setDialog({ kind: 'bands', size: { variantId: size.variantId, name: `${nameIn(locale, size.productNameAr, size.productNameEn)} · ${sizeName(t, size.values)}` }, facts: null, adding: true })}
                />
            ) : null}
            {dialog?.kind === 'bands' && dialog.size !== null ? (
                <Suspense fallback={null}>
                    <BandsDialog store={store} size={dialog.size} facts={dialog.facts} adding={dialog.adding} open onOpenChange={close} returnFocusTo={opener} />
                </Suspense>
            ) : null}
            {dialog?.kind === 'remove' && dialog.size !== null ? <RemoveBands frame={frame} size={dialog.size} onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function Row({ row, frame, onOpen }: { row: WholesaleRowData; frame: PricingFrameData; onOpen: (kind: 'bands' | 'remove', trigger: HTMLElement | null) => void }) {
    const t = useT();
    const locale = useLocale();
    const more = useRef<HTMLButtonElement>(null);
    const name = `${nameIn(locale, row.size.productNameAr, row.size.productNameEn)} · ${sizeName(t, row.size.values)}`;

    return (
        <TableRow data-test={`wholesale-row-${row.size.variantId}`}>
            <TableCell>
                <RowPhoto src={row.size.photo} />
            </TableCell>
            <TableCell className="text-label-14 text-ink">{nameIn(locale, row.size.productNameAr, row.size.productNameEn)}</TableCell>
            <TableCell>
                <span className="grid gap-1">
                    <span>{sizeName(t, row.size.values)}</span>
                    <span className="flex flex-wrap gap-1">
                        {row.keptNotSoldWholesale ? <Mark tone="gray-subtle">{t('pricing::admin_wholesale.mark.kept')}</Mark> : null}
                        {row.noRetailPrice ? <Mark tone="amber-subtle">{t('pricing::admin_wholesale.mark.no_retail')}</Mark> : null}
                        {row.minimumChanged ? <Mark tone="amber-subtle">{t('pricing::admin_wholesale.mark.minimum_changed')}</Mark> : null}
                    </span>
                    {row.noRetailPrice ? <span className="text-copy-12 text-ink-muted">{t('pricing::admin_wholesale.mark.no_retail_reason')}</span> : null}
                    {row.minimumChanged && row.minimum !== null ? (
                        <span className="text-copy-12 text-ink-muted">{t('pricing::admin_wholesale.mark.minimum_changed_reason', { minimum: figure(locale, row.minimum), first: figure(locale, row.bands[0]?.fromQuantity ?? 0) })}</span>
                    ) : null}
                </span>
            </TableCell>
            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                {row.size.code}
            </TableCell>
            <TableCell>{row.retail !== null ? <Money value={row.retail.price} /> : '—'}</TableCell>
            <TableCell className="tw-figure" data-test="bands">
                {row.bands.map((band) => t('pricing::admin_wholesale.band_line', { from: figure(locale, band.fromQuantity), price: ltr(band.price.text) })).join(' · ')}
            </TableCell>
            <TableCell className="tw-figure">{row.minimum === null ? '—' : figure(locale, row.minimum)}</TableCell>
            <TableCell className="text-end">
                {frame.mayEditWholesale ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <MoreButton ref={more} name={name} data-test={`wholesale-actions-${row.size.variantId}`} />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="min-w-56">
                            <Guarded label={t('pricing::admin_wholesale.menu.edit')} onSelect={() => onOpen('bands', more.current)} test="edit-wholesale" />
                            <DropdownMenuSeparator />
                            <Guarded destructive label={t('pricing::admin_wholesale.menu.remove')} onSelect={() => onOpen('remove', more.current)} test="remove-wholesale" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </TableCell>
        </TableRow>
    );
}

/** Add Wholesale Prices, first step: a size the store sells wholesale with no bands yet. */
function PickSize({ frame, onChoose, onOpenChange, returnFocusTo }: { frame: PricingFrameData; onChoose: (size: SizeData) => void; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useT();
    const locale = useLocale();
    const [chosen, setChosen] = useState<SizeData | null>(null);
    const label = (size: SizeData) => `${nameIn(locale, size.productNameAr, size.productNameEn)} · ${sizeName(t, size.values)}`;

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('pricing::admin_wholesale.add')}
            description={t('pricing::admin_wholesale.pick.body')}
            busy={false}
            confirm={
                <ActionButton type="button" disabled={chosen === null} onClick={() => (chosen === null ? undefined : onChoose(chosen))} data-test="confirm-pick">
                    {t('pricing::admin_wholesale.add')}
                </ActionButton>
            }
        >
            <RemoteCombobox<SizeData>
                id="wholesale-size"
                label={t('pricing::admin.column.size')}
                path="/admin/wholesale-prices/sizes"
                params={{ store: frame.storeCode }}
                rows={(answer) => (answer as SizeResultsData).rows}
                keyOf={(size) => size.variantId}
                labelOf={label}
                render={(size) => (
                    <span className="flex items-center gap-2">
                        <RowPhoto src={size.photo} />
                        <span className="grid min-w-0">
                            <span className="truncate">{label(size)}</span>
                            <span className="tw-figure font-mono text-copy-12 text-ink-muted" dir="ltr">
                                {size.code}
                            </span>
                        </span>
                    </span>
                )}
                shown={chosen === null ? t('pricing::admin_wholesale.pick.search') : label(chosen)}
                onChoose={setChosen}
                words={{ search: t('pricing::admin_wholesale.pick.search'), none: (query) => t('pricing::admin_wholesale.pick.none', { query }) }}
            />
        </PanelDialog>
    );
}

/** Remove Wholesale Prices: asked first; saving no bands removes them. */
function RemoveBands({ frame, size, onOpenChange, returnFocusTo }: { frame: PricingFrameData; size: { variantId: string; name: string }; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useT();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('pricing::admin.bands_dialog.remove')}
            description={t('pricing::admin.bands_dialog.remove_body', { size: size.name })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post('/admin/wholesale-prices', { store: frame.storeCode, size: size.variantId, bands: [] }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-remove-wholesale"
                >
                    {t('pricing::admin.bands_dialog.remove')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
