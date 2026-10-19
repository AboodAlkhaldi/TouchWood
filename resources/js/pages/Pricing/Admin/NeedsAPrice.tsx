import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { SearchField } from '@/components/SearchField';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useLoadMore } from '@/lib/use-load-more';
import type { NeedsAPricePage, SizeData } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { PriceBox, useBoxes } from './boxes';
import { nameIn, RowPhoto, Showing, sizeName, StoreFrame, useDebounced, useLocale, useT } from './parts';

/*
| **Needs a Price** (pricing.md §4.3.3 P5): the sizes switched on in the store with no price - customers
| can't order them -, 50 at a time with the total (Q14), found by name or code. Each row a box; the save bar
| as Prices' ("Save Prices"); a saved row leaves the list. In a store wired to a provider, read-only: the
| provider's next read prices these.
*/

export default function NeedsAPrice(page: NeedsAPricePage) {
    const { storeFrame: frame, rows, next, total, search } = page;
    const t = useT();
    const locale = useLocale();
    const [query, setQuery] = useState(search);
    const list = useLoadMore<SizeData>(rows, (row) => row.variantId);
    const editable = frame.mayEditPrices && !frame.wired;
    const boxes = useBoxes(
        list.rows.map((row) => ({ id: row.variantId, saved: '' })),
        { store: frame.storeCode, decimals: frame.decimals },
    );
    const typed = useDebounced(query, 300);

    useEffect(() => {
        if (typed !== search) {
            router.get('/admin/needs-a-price', typed === '' ? { store: frame.storeCode } : { store: frame.storeCode, q: typed }, { preserveState: true });
        }
        // Only a search that settled runs it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    return (
        <AdminLayout title={t('pricing::admin_needs_a_price.title')} subtitle={t('pricing::admin_needs_a_price.subtitle')}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} wiredBody={t('pricing::admin_needs_a_price.wired')} />

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                        <SearchField id="needs-search" label={t('pricing::admin_prices.filters.search')} placeholder={t('pricing::admin.search_sizes')} value={query} onValueChange={setQuery} className="max-w-sm" data-test="needs-search" />
                        {search !== '' ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setQuery('');
                                    router.get('/admin/needs-a-price', { store: frame.storeCode }, { preserveState: true });
                                }}
                            >
                                {t('pricing::admin.clear_filters')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="needs-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(search !== '' ? 'pricing::admin_needs_a_price.empty.filtered_title' : 'pricing::admin_needs_a_price.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(search !== '' ? 'pricing::admin_needs_a_price.empty.filtered_body' : 'pricing::admin_needs_a_price.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('pricing::admin_needs_a_price.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead className="w-14">
                                            <span className="sr-only">{t('pricing::admin.column.photo')}</span>
                                        </TableHead>
                                        <TableHead>{t('pricing::admin.column.product')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.size')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.code')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.price')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => {
                                        const id = row.variantId;
                                        const product = nameIn(locale, row.productNameAr, row.productNameEn);

                                        return (
                                            <TableRow key={id} data-test={`needs-row-${id}`}>
                                                <TableCell>
                                                    <RowPhoto src={row.photo} />
                                                </TableCell>
                                                <TableCell className="text-label-14 text-ink">{product}</TableCell>
                                                <TableCell>{sizeName(t, row.values)}</TableCell>
                                                <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {editable ? (
                                                        <PriceBox
                                                            id={boxes.idOf(id)}
                                                            label={t('pricing::admin.box.label', { size: `${product} · ${sizeName(t, row.values)}` })}
                                                            currency={frame.currency}
                                                            value={boxes.valueOf({ id, saved: '' })}
                                                            onChange={(value) => boxes.type(id, value)}
                                                            onEscape={() => boxes.reset(id)}
                                                            onEnter={boxes.save}
                                                            changed={boxes.changed(id)}
                                                            error={boxes.error(id)}
                                                        />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/needs-a-price', { store: frame.storeCode, ...(search === '' ? {} : { q: search }), after: next }, ['rows', 'next', 'total'])} /> : null}

                {boxes.bar(t('pricing::admin.box.save'))}
            </div>
        </AdminLayout>
    );
}
