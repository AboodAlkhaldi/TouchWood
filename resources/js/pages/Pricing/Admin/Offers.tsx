import { cloneElement } from 'react';
import { router } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { figure } from '@/lib/digits';
import { useLoadMore } from '@/lib/use-load-more';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { OffersPage, PickedProductData, ProductResultsData, SaleRowData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { OfferMenu, useOfferDialogs } from './OfferActions';
import { Mark, nameIn, Off, plural, RowPhoto, Showing, StateBadge, StoreFrame, useLocale, useT } from './parts';
import { RemoteCombobox } from './picker';

/*
| **Offers** (pricing.md §4.3.3 P2; "Offer" is the panel's word for a sale, §9.4): the store's offers - All,
| Running, Scheduled or Ended; of one product (a picker of the products with offers here); or the one a link
| names (`?sale=`, with Show All Offers) - **By State** (running ending soonest, scheduled starting soonest,
| ended last ended first; the default) or **Newest Added** (Q7). Each row's ⋯ by its state (OfferActions).
| **Add Offer** for whoever edits prices and offers in the store.
*/

const STATES = ['ALL', 'RUNNING', 'SCHEDULED', 'ENDED'] as const;
const SORTS = ['BY_STATE', 'NEWEST'] as const;

export default function Offers(page: OffersPage) {
    const { storeFrame: frame, sales, next, total, state, sort, product, saleId } = page;
    const t = useT();
    const locale = useLocale();
    const list = useLoadMore<SaleRowData>(sales, (row) => row.id);
    const offers = useOfferDialogs({ code: frame.storeCode, decimals: frame.decimals, currency: frame.currency, zone: page.storeTimezone });
    const filtered = state !== '' || product !== null;
    const params = (change: Record<string, string | null>) => {
        const all: Record<string, string | null> = { store: frame.storeCode, state: state === '' ? null : state.toLowerCase(), sort: sort === 'BY_STATE' ? null : sort.toLowerCase(), product: product?.productId ?? null, ...change };

        return Object.fromEntries(Object.entries(all).filter((entry): entry is [string, string] => entry[1] !== null && entry[1] !== ''));
    };
    const visit = (change: Record<string, string | null>) => router.get('/admin/offers', params(change), { preserveState: true });
    const add = frame.mayEditPrices ? (
        <ActionButton type="button" onClick={(event) => offers.open({ mode: 'add', sale: null }, event.currentTarget)} data-test="add-offer">
            {t('pricing::admin.offer.add')}
        </ActionButton>
    ) : undefined;

    return (
        <AdminLayout title={t('pricing::admin_offers.title')} subtitle={t('pricing::admin_offers.subtitle')} action={add}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                {saleId !== null ? (
                    <Note
                        variant="secondary"
                        action={
                            <Button type="button" variant="outline" size="sm" onClick={() => router.get('/admin/offers', { store: frame.storeCode })} data-test="show-all-offers">
                                {t('pricing::admin_offers.show_all')}
                            </Button>
                        }
                    >
                        {t('pricing::admin_offers.one_offer')}
                    </Note>
                ) : (
                    <Card className="material-base gap-0 border-0 py-0">
                        <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                            <div className="grid gap-2">
                                <span className="text-label-14 text-ink">{t('pricing::admin_offers.filters.state')}</span>
                                <ToggleGroup
                                    type="single"
                                    value={state === '' ? 'ALL' : state}
                                    onValueChange={(next) => (next === '' ? undefined : visit({ state: next === 'ALL' ? null : next.toLowerCase() }))}
                                    aria-label={t('pricing::admin_offers.filters.state')}
                                    className={SWITCH_TRACK}
                                    data-test="offers-state"
                                >
                                    {STATES.map((each) => (
                                        <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM} data-test={`state-${each}`}>
                                            {t(`pricing::admin_offers.filters.${each}`)}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>
                            <div className="w-64">
                                <RemoteCombobox<PickedProductData>
                                    id="offers-product"
                                    label={t('pricing::admin_offers.filters.product')}
                                    path="/admin/offers/filter-products"
                                    params={{ store: frame.storeCode }}
                                    rows={(answer) => (answer as ProductResultsData).rows}
                                    keyOf={(item) => item.productId}
                                    labelOf={(item) => nameIn(locale, item.nameAr, item.nameEn)}
                                    render={(item) => (
                                        <span className="flex items-center gap-2">
                                            <RowPhoto src={item.photo} />
                                            <span className="grid min-w-0">
                                                <span className="truncate">{nameIn(locale, item.nameAr, item.nameEn)}</span>
                                                <span className="tw-figure truncate font-mono text-copy-12 text-ink-muted" dir="ltr">
                                                    {item.codes.join(' · ')}
                                                </span>
                                            </span>
                                        </span>
                                    )}
                                    shown={product === null ? t('pricing::admin_offers.filters.any_product') : nameIn(locale, product.nameAr, product.nameEn) || t('pricing::admin_offers.filters.product')}
                                    onChoose={(item) => visit({ product: item.productId })}
                                    words={{ search: t('pricing::admin_offers.filters.product_search'), none: (query) => t('pricing::admin_offers.filters.product_none', { query }) }}
                                />
                            </div>
                            <div className="grid gap-2">
                                <span className="text-label-14 text-ink">{t('pricing::admin_offers.filters.sort')}</span>
                                <ToggleGroup
                                    type="single"
                                    value={sort}
                                    onValueChange={(next) => (next === '' ? undefined : visit({ sort: next === 'BY_STATE' ? null : next.toLowerCase() }))}
                                    aria-label={t('pricing::admin_offers.filters.sort')}
                                    className={SWITCH_TRACK}
                                    data-test="offers-sort"
                                >
                                    {SORTS.map((each) => (
                                        <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM} data-test={`sort-${each}`}>
                                            {t(`pricing::admin_offers.filters.${each}`)}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>
                            {filtered ? (
                                <Button type="button" variant="ghost" onClick={() => visit({ state: null, product: null })} data-test="clear-filters">
                                    {t('pricing::admin.clear_filters')}
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                )}

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="offers-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(filtered ? 'pricing::admin_offers.empty.filtered_title' : 'pricing::admin_offers.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(filtered ? 'pricing::admin_offers.empty.filtered_body' : 'pricing::admin_offers.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        {!filtered && add !== undefined ? <EmptyContent>{cloneElement(add, { 'data-test': 'add-offer-empty' })}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('pricing::admin_offers.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('pricing::admin.column.product')}</TableHead>
                                        <TableHead>{t('pricing::admin_offers.column.sizes')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.price')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.starts')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.ends')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.state')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.always_wins')}</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t('pricing::admin.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((sale) => {
                                        const name = nameIn(locale, sale.productNameAr, sale.productNameEn);

                                        return (
                                            <TableRow key={sale.id} data-test={`offer-${sale.id}`}>
                                                <TableCell>
                                                    {frame.mayViewProducts ? (
                                                        <Link href={`/admin/products/${sale.productId}`} className="text-label-14 text-ink hover:underline">
                                                            {name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-label-14 text-ink">{name}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell data-test="offer-sizes">
                                                    <span className="flex flex-wrap items-center gap-2">
                                                        {sale.allSizes ? (
                                                            t('pricing::admin.offer.all_sizes')
                                                        ) : (
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <span tabIndex={0} className="tw-figure underline decoration-dotted underline-offset-4">
                                                                        {t('pricing::admin.offer.of_sizes', { count: figure(locale, sale.sizesOn), total: figure(locale, sale.productSizesOn) })}
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>{sale.sizeNames.join(t('pricing::admin.list_separator'))}</TooltipContent>
                                                            </Tooltip>
                                                        )}
                                                        {sale.archivedSince > 0 ? <Mark tone="amber-subtle">{plural(t, locale, 'pricing::admin.offer.archived_since', sale.archivedSince)}</Mark> : null}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <Off percentBasisPoints={sale.percentBasisPoints} money={sale.salePrice} />
                                                </TableCell>
                                                <TableCell>
                                                    <Time value={sale.startsAt} />
                                                </TableCell>
                                                <TableCell>{sale.endsAt === null ? t('pricing::admin.no_end') : <Time value={sale.endsAt} />}</TableCell>
                                                <TableCell>
                                                    <StateBadge state={sale.state} />
                                                </TableCell>
                                                <TableCell>{sale.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}</TableCell>
                                                <TableCell className="text-end">
                                                    <OfferMenu sale={sale} mayEdit={frame.mayEditPrices} name={name} onOpen={(mode, trigger) => offers.open({ mode, sale }, trigger)} />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/offers', { ...params({}), ...(saleId === null ? {} : { sale: saleId }), after: next }, ['sales', 'next', 'total'])} /> : null}
            </div>

            {offers.dialogs}
        </AdminLayout>
    );
}
