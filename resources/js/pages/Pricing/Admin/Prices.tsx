import { lazy, Suspense, useEffect, useRef, useState, type RefObject } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Money } from '@/components/Money';
import { PanelDialog } from '@/components/PanelDialog';
import { SearchField } from '@/components/SearchField';
import { SizeDrawer, useAddressDrawer } from '@/components/SizeDrawer';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { figure } from '@/lib/digits';
import { useLoadMore } from '@/lib/use-load-more';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { PriceRowData, PricesPage, PricingFrameData, SizePricesData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { minorOf, PriceBox, typedOf, useBoxes } from './boxes';
import type { OfferStore } from './OfferDialog';
import { useOfferDialogs } from './OfferActions';
import { offText } from './discount-parts';
import { Guarded, ltr, Mark, MoreButton, nameIn, otherName, percentOf, plural, RowPhoto, Showing, sizeName, StateBadge, StoreFrame, ToArrow, useDate, useDebounced, useLocale, useT } from './parts';

// The bands dialog loads when it is first opened, not with the page.
const BandsDialog = lazy(() => import('./BandsDialog').then((module) => ({ default: module.BandsDialog })));

/*
| **Prices** (pricing.md §4.3.3 P1): the store's sizes switched on, and those priced here though switched
| off, by product name then the size's place (Q16), 50 at a time with Show More. Each row: its photo, product
| and size, code, retail price - a box for whoever edits prices in a store not wired to a provider, its
| source and when under it -, what one piece costs today and why, its wholesale bands. A row's ⋯: View
| Prices (the drawer, in the address, Q3), Add Offer…, Edit Wholesale Prices…, Remove Price…. Only the
| product's name is a link: a click in a box opens nothing.
*/

const FILTERS = ['ALL', 'NO_PRICE', 'ON_SALE_NOW', 'HAS_WHOLESALE'] as const;

type Dialog = { kind: 'remove' | 'bands'; row: { variantId: string; name: string } } | null;

export default function Prices(page: PricesPage & { drawer: SizePricesData | null }) {
    const { storeFrame: frame, rows, next, total, search, filter } = page;
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState(search);
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<PriceRowData>(rows, (row) => row.size.variantId);
    const drawer = useAddressDrawer('size', ['drawer']);
    const store: OfferStore = { code: frame.storeCode, decimals: frame.decimals, currency: frame.currency, zone: page.storeTimezone };
    const offers = useOfferDialogs(store);
    const editable = frame.mayEditPrices && !frame.wired;
    const boxes = useBoxes(
        list.rows.filter((row) => !row.size.archived).map((row) => ({ id: row.size.variantId, saved: row.retail === null ? '' : typedOf(row.retail.price.minor, frame.decimals) })),
        { store: frame.storeCode, decimals: frame.decimals },
    );
    const filtered = search !== '' || filter !== 'ALL';
    const visit = (params: { q?: string; filter?: string }) => {
        const next = { store: frame.storeCode, q: params.q ?? search, filter: params.filter ?? filter };

        router.get('/admin/prices', Object.fromEntries(Object.entries(next).filter(([key, value]) => value !== '' && !(key === 'filter' && value === 'ALL'))), { preserveState: true });
    };
    // The search runs 300 ms after the last key (§4.3.3 P1).
    const typed = useDebounced(query, 300);

    useEffect(() => {
        if (typed !== search) {
            visit({ q: typed });
        }
        // Only a search that settled runs it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const nameOf = (row: PriceRowData) => `${nameIn(locale, row.size.productNameAr, row.size.productNameEn)} · ${sizeName(t, row.size.values)}`;
    const open = (kind: 'remove' | 'bands', row: PriceRowData, trigger: HTMLElement | null) => {
        opener.current = trigger;
        setDialog({ kind, row: { variantId: row.size.variantId, name: sizeName(t, row.size.values) } });
    };

    return (
        <AdminLayout title={t('pricing::admin_prices.title')} subtitle={t('pricing::admin_prices.subtitle')}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                        <SearchField id="prices-search" label={t('pricing::admin_prices.filters.search')} placeholder={t('pricing::admin.search_sizes')} value={query} onValueChange={setQuery} className="max-w-sm" data-test="prices-search" />
                        <ToggleGroup
                            type="single"
                            value={filter}
                            onValueChange={(next) => (next === '' ? undefined : visit({ filter: next }))}
                            aria-label={t('pricing::admin_prices.filters.show')}
                            className={`${SWITCH_TRACK} flex-wrap`}
                            data-test="prices-filter"
                        >
                            {FILTERS.map((each) => (
                                <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM} data-test={`filter-${each}`}>
                                    {t(`pricing::admin_prices.filters.${each}`)}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                        {filtered ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setQuery('');
                                    router.get('/admin/prices', { store: frame.storeCode }, { preserveState: true });
                                }}
                                data-test="clear-filters"
                            >
                                {t('pricing::admin.clear_filters')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="prices-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(filtered ? 'pricing::admin_prices.empty.filtered_title' : 'pricing::admin_prices.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(filtered ? 'pricing::admin_prices.empty.filtered_body' : 'pricing::admin_prices.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('pricing::admin_prices.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-14">
                                            <span className="sr-only">{t('pricing::admin.column.photo')}</span>
                                        </TableHead>
                                        <TableHead>{t('pricing::admin.column.product')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.size')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.code')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.retail_price')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.today')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.wholesale')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('pricing::admin.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => {
                                        const id = row.size.variantId;
                                        const typedMinor = boxes.changed(id) ? minorOf(boxes.valueOf({ id, saved: '' }), frame.decimals) : null;
                                        const warnings = [
                                            typedMinor !== null && row.today?.why === 'SALE' && typedMinor < row.today.price.minor ? t('pricing::admin.box.below_offer', { price: ltr(row.today.price.text) }) : null,
                                            typedMinor !== null && row.firstBand !== null && typedMinor < row.firstBand.price.minor ? t('pricing::admin.box.below_band', { price: ltr(row.firstBand.price.text) }) : null,
                                        ].filter((each): each is string => each !== null);

                                        return (
                                            <TableRow key={id} data-test={`price-row-${id}`}>
                                                <TableCell>
                                                    <RowPhoto src={row.size.photo} />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="grid">
                                                        {/* The product's name opens the drawer (Q3); the boxes open nothing. */}
                                                        <Link
                                                            href={`/admin/prices?store=${frame.storeCode}&size=${id}`}
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
                                                        {!row.size.switchedOn ? <Mark tone="gray-subtle">{t('pricing::admin.badge.not_switched_on')}</Mark> : null}
                                                        {row.size.archived ? <Mark tone="amber-subtle" test="archived">{t('pricing::admin.badge.archived')}</Mark> : null}
                                                    </span>
                                                    {/* Read-only, and why (§4.3.3 P1 "Marks on a row"). */}
                                                    {row.size.archived ? <span className="mt-1 block max-w-56 text-copy-12 text-ink-muted">{frame.reasons.archived}</span> : null}
                                                </TableCell>
                                                <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                                    {row.size.code}
                                                </TableCell>
                                                <TableCell className="align-top" data-test="retail">
                                                    {editable && !row.size.archived ? (
                                                        <PriceBox
                                                            id={`price-${id}`}
                                                            label={t('pricing::admin.box.label', { size: nameOf(row) })}
                                                            currency={frame.currency}
                                                            value={boxes.valueOf({ id, saved: row.retail === null ? '' : typedOf(row.retail.price.minor, frame.decimals) })}
                                                            onChange={(value) => boxes.type(id, value)}
                                                            onEscape={() => boxes.reset(id)}
                                                            onEnter={boxes.save}
                                                            changed={boxes.changed(id)}
                                                            error={boxes.error(id)}
                                                            warning={warnings.join(' ') || undefined}
                                                        >
                                                            <RetailSource row={row} />
                                                        </PriceBox>
                                                    ) : row.retail !== null ? (
                                                        <span className="grid gap-1">
                                                            <Money value={row.retail.price} />
                                                            <RetailSource row={row} />
                                                        </span>
                                                    ) : (
                                                        <RetailSource row={row} />
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top" data-test="today">
                                                    <Today row={row} zone={page.storeTimezone} />
                                                </TableCell>
                                                <TableCell className="align-top" data-test="wholesale">
                                                    {row.bands === 0 ? (
                                                        '—'
                                                    ) : row.keptNotSoldWholesale ? (
                                                        <Mark tone="gray-subtle">{t('pricing::admin.kept_not_sold')}</Mark>
                                                    ) : (
                                                        <span className="tw-figure">{plural(t, locale, 'pricing::admin.bands', row.bands)}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end align-top">
                                                    <RowMenu
                                                        row={row}
                                                        frame={frame}
                                                        name={nameOf(row)}
                                                        onView={(trigger) => {
                                                            opener.current = trigger;
                                                            drawer.open(id);
                                                        }}
                                                        onOffer={(trigger) => offers.open({ mode: 'add', sale: null, product: { productId: row.size.productId, nameAr: row.size.productNameAr, nameEn: row.size.productNameEn, photo: row.size.photo, codes: [row.size.code] }, sizes: [id] }, trigger)}
                                                        onBands={(trigger) => open('bands', row, trigger)}
                                                        onRemove={(trigger) => open('remove', row, trigger)}
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
                        onClick={() => list.more('/admin/prices', Object.fromEntries(Object.entries({ store: frame.storeCode, q: search, filter: filter === 'ALL' ? '' : filter, after: next }).filter(([, value]) => value !== '')), ['rows', 'next', 'total'])}
                    />
                ) : null}

                {boxes.bar(t('pricing::admin.box.save'))}
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
                        frame={frame}
                        zone={page.storeTimezone}
                        onBands={(trigger) => {
                            opener.current = trigger;
                            setDialog({ kind: 'bands', row: { variantId: page.drawer?.size.variantId ?? '', name: sizeName(t, page.drawer?.size.values ?? []) } });
                        }}
                    />
                ) : null}
            </SizeDrawer>

            {offers.dialogs}
            {dialog !== null && dialog.kind === 'remove' ? <RemovePriceDialog frame={frame} size={dialog.row} onOpenChange={(next) => (next ? undefined : setDialog(null))} returnFocusTo={opener} /> : null}
            {dialog !== null && dialog.kind === 'bands' ? (
                <Suspense fallback={null}>
                    <BandsDialog store={{ code: frame.storeCode, decimals: frame.decimals, currency: frame.currency }} size={dialog.row} open onOpenChange={(next) => (next ? undefined : setDialog(null))} returnFocusTo={opener} />
                </Suspense>
            ) : null}
        </AdminLayout>
    );
}

/** Under a retail price: where it came from and when it last changed; "No price" without one. */
function RetailSource({ row }: { row: PriceRowData }) {
    const t = useT();

    if (row.retail === null) {
        return (
            <span className="text-copy-12 text-ink-muted" data-test="no-price">
                {row.size.archived ? null : '— '}
                {t('pricing::admin.no_price')}
            </span>
        );
    }

    return (
        <span className="text-copy-12 text-ink-muted">
            {t(`pricing::admin.source.${row.retail.source}`)} · <Time value={row.retail.updatedAt} inSentence />
        </span>
    );
}

/** What one piece costs now, and why (§1.6): the usual price struck through while an offer or discount wins. */
function Today({ row, zone }: { row: PriceRowData; zone: string }) {
    const t = useT();
    const date = useDate(zone);

    if (row.wholesaleOnly) {
        return <Mark tone="gray-subtle">{t('pricing::admin.badge.wholesale_only')}</Mark>;
    }

    if (row.today === null) {
        return <>—</>;
    }

    const today = row.today;

    return (
        <span className="grid gap-1">
            {today.before !== null ? <Money value={today.before} className="text-copy-12 text-ink-muted line-through" /> : null}
            <Money value={today.price} />
            {today.why === 'SALE' || today.why === 'CATEGORY' ? (
                <span className="flex flex-wrap items-center gap-1">
                    <Mark tone="green-subtle">{today.why === 'SALE' ? t('pricing::admin.badge.offer') : t('pricing::admin.badge.discount')}</Mark>
                    {today.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}
                </span>
            ) : null}
            {today.endsAt !== null && today.why !== 'BASE' ? <span className="tw-figure text-copy-12 text-ink-muted">{t('pricing::admin.until', { date: date(today.endsAt) })}</span> : null}
        </span>
    );
}

function RowMenu({
    row,
    frame,
    name,
    onView,
    onOffer,
    onBands,
    onRemove,
}: {
    row: PriceRowData;
    frame: PricingFrameData;
    name: string;
    onView: (trigger: HTMLElement | null) => void;
    onOffer: (trigger: HTMLElement | null) => void;
    onBands: (trigger: HTMLElement | null) => void;
    onRemove: (trigger: HTMLElement | null) => void;
}) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);
    const reasons = frame.reasons;
    const archived = row.size.archived ? reasons.archived : null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={`price-actions-${row.size.variantId}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                <Guarded label={t('pricing::admin.menu.view_prices')} onSelect={() => onView(more.current)} test="view-prices" />
                {frame.mayEditPrices ? <Guarded label={t('pricing::admin.menu.add_offer')} reason={archived ?? (row.retail === null ? reasons.noPrice : null)} onSelect={() => onOffer(more.current)} test="add-offer" /> : null}
                {frame.mayEditWholesale ? (
                    <Guarded
                        label={t('pricing::admin.menu.edit_wholesale')}
                        reason={archived ?? (!row.soldWholesale ? reasons.notSoldWholesale : row.retail === null ? reasons.noPrice : null)}
                        onSelect={() => onBands(more.current)}
                        test="edit-wholesale"
                    />
                ) : null}
                {frame.mayEditPrices && row.retail !== null ? (
                    <>
                        <DropdownMenuSeparator />
                        <Guarded destructive label={t('pricing::admin.menu.remove_price')} reason={frame.wired ? reasons.wiredPrice : archived} onSelect={() => onRemove(more.current)} test="remove-price" />
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The drawer's body (Q3): the retail price - its box for whoever may type it -, today's prices and why, and what reaches the size. */
function DrawerBody({ view, frame, zone, onBands }: { view: SizePricesData; frame: PricingFrameData; zone: string; onBands: (trigger: HTMLElement | null) => void }) {
    const t = useT();
    const locale = useLocale();
    const date = useDate(zone);
    const id = view.size.variantId;
    const boxes = useBoxes(view.size.archived ? [] : [{ id, saved: view.retail === null ? '' : typedOf(view.retail.price.minor, frame.decimals) }], { store: frame.storeCode, decimals: frame.decimals });
    const editable = frame.mayEditPrices && !frame.wired && !view.size.archived;
    const offOf = (percent: number | null, money: { text: string } | null) => ltr(percent !== null ? `−${percentOf(locale, percent)}%` : (money?.text ?? ''));

    return (
        <div className="grid gap-6" data-test="drawer-body">
            <section className="grid gap-2" aria-labelledby="drawer-retail">
                <h3 id="drawer-retail" className="text-heading-14 text-ink">
                    {t('pricing::admin_prices.drawer.retail')}
                </h3>
                {editable ? (
                    <div className="grid gap-2">
                        <PriceBox
                            id={`price-${id}`}
                            label={t('pricing::admin.box.label', { size: sizeName(t, view.size.values) })}
                            currency={frame.currency}
                            value={boxes.valueOf({ id, saved: view.retail === null ? '' : typedOf(view.retail.price.minor, frame.decimals) })}
                            onChange={(value) => boxes.type(id, value)}
                            onEscape={() => boxes.reset(id)}
                            onEnter={boxes.save}
                            changed={boxes.changed(id)}
                            error={boxes.error(id)}
                        />
                        <ActionButton type="button" className="w-fit" disabled={!boxes.changed(id)} loading={boxes.saving} onClick={boxes.save} data-test="drawer-save">
                            {t('pricing::admin.box.save_one')}
                        </ActionButton>
                        {boxes.leave}
                    </div>
                ) : view.retail !== null ? (
                    <Money value={view.retail.price} />
                ) : (
                    <p className="text-copy-14 text-ink-muted">{t('pricing::admin.no_price')}</p>
                )}
                {view.retail !== null ? (
                    <p className="text-copy-12 text-ink-muted">
                        {t(`pricing::admin.source.${view.retail.source}`)} · <Time value={view.retail.updatedAt} inSentence />
                    </p>
                ) : null}
            </section>

            <section className="grid gap-2" aria-labelledby="drawer-today">
                <h3 id="drawer-today" className="text-heading-14 text-ink">
                    {t('pricing::admin_prices.drawer.today')}
                </h3>
                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-copy-14">
                    <dt className="text-ink-muted">{t('pricing::admin_prices.drawer.one_piece')}</dt>
                    <dd>
                        {!view.soldRetail ? (
                            t('pricing::admin_prices.drawer.not_sold_retail')
                        ) : view.todayRetail === null ? (
                            '—'
                        ) : (
                            <span className="grid">
                                <Money value={view.todayRetail.price} />
                                <span className="text-copy-12 text-ink-muted">{t(`pricing::admin_prices.drawer.why.${view.todayRetail.why}`)}</span>
                            </span>
                        )}
                    </dd>
                    {view.wholesaleQuantity !== null ? (
                        <>
                            <dt className="text-ink-muted">{plural(t, locale, 'pricing::admin_prices.drawer.wholesale_line', view.wholesaleQuantity)}</dt>
                            <dd>
                                {view.todayWholesale === null ? (
                                    '—'
                                ) : (
                                    <span className="grid">
                                        <Money value={view.todayWholesale.price} />
                                        <span className="text-copy-12 text-ink-muted">{t(`pricing::admin_prices.drawer.why.${view.todayWholesale.why}`)}</span>
                                    </span>
                                )}
                            </dd>
                        </>
                    ) : null}
                </dl>
            </section>

            <section className="grid gap-3" aria-labelledby="drawer-reaches">
                <h3 id="drawer-reaches" className="text-heading-14 text-ink">
                    {t('pricing::admin_prices.drawer.reaches')}
                </h3>
                {view.sales.length === 0 && view.discounts.length === 0 && view.bands.length === 0 ? <p className="text-copy-14 text-ink-muted">{t('pricing::admin_prices.drawer.nothing')}</p> : null}
                <ul className="grid gap-3">
                    {view.sales.map((sale) => (
                        <li key={sale.id} className="grid gap-1" data-test={`reach-offer-${sale.id}`}>
                            <span className="flex flex-wrap items-center gap-2">
                                <StateBadge state={sale.state} />
                                <span className="text-copy-14 text-ink">{t('pricing::admin_prices.drawer.offer', { off: offOf(sale.percentBasisPoints, sale.salePrice) })}</span>
                                {sale.priceOnSize !== null ? (
                                    <span className="text-copy-13">
                                        <ToArrow /> <Money value={sale.priceOnSize} />
                                    </span>
                                ) : null}
                                {sale.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}
                                {sale.winsNow ? <Mark tone="green-subtle">{t('pricing::admin_prices.drawer.wins_now')}</Mark> : null}
                            </span>
                            <span className="tw-figure text-copy-12 text-ink-muted">{t('pricing::admin.note.dates', { start: date(sale.startsAt), end: sale.endsAt === null ? t('pricing::admin.no_end') : date(sale.endsAt) })}</span>
                            <Link href={`/admin/offers?store=${frame.storeCode}&sale=${sale.id}`} className="w-fit text-copy-13 text-ink underline underline-offset-4">
                                {t('pricing::admin_prices.drawer.open_offer')}
                            </Link>
                        </li>
                    ))}
                    {view.discounts.map((discount) => (
                        <li key={discount.id} className="grid gap-1" data-test={`reach-discount-${discount.id}`}>
                            <span className="flex flex-wrap items-center gap-2">
                                <StateBadge state={discount.state} />
                                <span className="text-copy-14 text-ink">
                                    {t('pricing::admin.discount_line', { category: discount.categoryPath.join(' › '), off: discount.percentBasisPoints !== null ? t('pricing::admin.percent_off', { percent: ltr(percentOf(locale, discount.percentBasisPoints)) }) : offText(t, locale, discount) })}
                                </span>
                                {discount.priceOnSize !== null ? (
                                    <span className="text-copy-13">
                                        <ToArrow /> <Money value={discount.priceOnSize} />
                                    </span>
                                ) : null}
                                {discount.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}
                                {discount.winsNow ? <Mark tone="green-subtle">{t('pricing::admin_prices.drawer.wins_now')}</Mark> : null}
                            </span>
                            {discount.skipped !== null ? <span className="text-copy-12 text-warn">{t(`pricing::admin_prices.drawer.skipped.${discount.skipped}`)}</span> : null}
                            <span className="tw-figure text-copy-12 text-ink-muted">{t('pricing::admin.note.dates', { start: date(discount.startsAt), end: discount.endsAt === null ? t('pricing::admin.no_end') : date(discount.endsAt) })}</span>
                            <Link href={`/admin/category-discounts/${discount.id}`} className="w-fit text-copy-13 text-ink underline underline-offset-4">
                                {t('pricing::admin_prices.drawer.open_discount')}
                            </Link>
                        </li>
                    ))}
                    {view.bands.length > 0 ? (
                        <li className="grid gap-1" data-test="reach-bands">
                            <span className="text-copy-14 text-ink">{t('pricing::admin_prices.drawer.bands')}</span>
                            <span className="tw-figure text-copy-13 text-ink-muted">{view.bands.map((band) => t('pricing::admin_prices.drawer.band_line', { from: figure(locale, band.fromQuantity), price: ltr(band.price.text) })).join(' · ')}</span>
                            {view.minimumChanged && view.wholesaleMinimum !== null ? (
                                <span className="text-copy-12 text-warn">{t('pricing::admin_prices.drawer.minimum_changed', { minimum: figure(locale, view.wholesaleMinimum), first: figure(locale, view.bands[0]?.fromQuantity ?? 0) })}</span>
                            ) : null}
                            {frame.mayEditWholesale ? (
                                <Button type="button" variant="link" className="h-auto w-fit p-0 text-copy-13" onClick={(event) => onBands(event.currentTarget)} data-test="drawer-bands">
                                    {t('pricing::admin.menu.edit_wholesale')}
                                </Button>
                            ) : null}
                        </li>
                    ) : null}
                </ul>
            </section>
        </div>
    );
}

/** Remove Price: asked first (§4.3.3 P1). */
function RemovePriceDialog({ frame, size, onOpenChange, returnFocusTo }: { frame: PricingFrameData; size: { variantId: string; name: string }; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useT();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t('pricing::admin.remove_price.title')}
            description={t('pricing::admin.remove_price.body', { size: size.name, store: frame.storeName })}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() =>
                        router.post('/admin/prices/remove', { store: frame.storeCode, sizes: [size.variantId] }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-remove-price"
                >
                    {t('pricing::admin.remove_price.title')}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}

