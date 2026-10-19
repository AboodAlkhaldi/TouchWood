import { cloneElement, lazy, Suspense, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useLoadMore } from '@/lib/use-load-more';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import type { DiscountRowData, DiscountsPage } from '@/types/generated/Modules/Pricing/Presentation/Http/Resource';
import { DiscountOff } from './discount-parts';
import { Mark, plural, Showing, StateBadge, StoreFrame, useLocale, useT } from './parts';

// The dialog - its pickers, its preview, its date fields - loads when it is first opened.
const DiscountDialog = lazy(() => import('./DiscountDialog').then((module) => ({ default: module.DiscountDialog })));

/*
| **Category Discounts** (pricing.md §4.3.3 P3): the store's discounts, filtered and ordered as Offers (Q7),
| each row its category's path - the row opens the discount's page, where its actions are -, how much off,
| the lines, dates, state, Always Wins, and the products it leaves out ("3 products", named in a tooltip).
| **Add Discount** for whoever manages category discounts in the store.
*/

const STATES = ['ALL', 'RUNNING', 'SCHEDULED', 'ENDED'] as const;
const SORTS = ['BY_STATE', 'NEWEST'] as const;

export default function CategoryDiscounts(page: DiscountsPage) {
    const { storeFrame: frame, discounts, next, total, state, sort } = page;
    const t = useT();
    const locale = useLocale();
    const [adding, setAdding] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const list = useLoadMore<DiscountRowData>(discounts, (row) => row.id);
    const filtered = state !== '';
    const params = (change: Record<string, string | null>) => {
        const all: Record<string, string | null> = { store: frame.storeCode, state: state === '' ? null : state.toLowerCase(), sort: sort === 'BY_STATE' ? null : sort.toLowerCase(), ...change };

        return Object.fromEntries(Object.entries(all).filter((entry): entry is [string, string] => entry[1] !== null && entry[1] !== ''));
    };
    const visit = (change: Record<string, string | null>) => router.get('/admin/category-discounts', params(change), { preserveState: true });
    const add = frame.mayManageDiscounts ? (
        <ActionButton
            type="button"
            onClick={(event) => {
                opener.current = event.currentTarget;
                setAdding(true);
            }}
            data-test="add-discount"
        >
            {t('pricing::admin_discounts.add')}
        </ActionButton>
    ) : undefined;

    return (
        <AdminLayout title={t('pricing::admin_discounts.title')} subtitle={t('pricing::admin_discounts.subtitle')} action={add}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />

                <Card className="material-base gap-0 border-0 py-0">
                    <CardContent className="flex flex-wrap items-end gap-4 px-5 py-4">
                        <div className="grid gap-2">
                            <span className="text-label-14 text-ink">{t('pricing::admin_offers.filters.state')}</span>
                            <ToggleGroup
                                type="single"
                                value={state === '' ? 'ALL' : state}
                                onValueChange={(value) => (value === '' ? undefined : visit({ state: value === 'ALL' ? null : value.toLowerCase() }))}
                                aria-label={t('pricing::admin_offers.filters.state')}
                                className={SWITCH_TRACK}
                                data-test="discounts-state"
                            >
                                {STATES.map((each) => (
                                    <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM}>
                                        {t(`pricing::admin_offers.filters.${each}`)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </div>
                        <div className="grid gap-2">
                            <span className="text-label-14 text-ink">{t('pricing::admin_offers.filters.sort')}</span>
                            <ToggleGroup
                                type="single"
                                value={sort}
                                onValueChange={(value) => (value === '' ? undefined : visit({ sort: value === 'BY_STATE' ? null : value.toLowerCase() }))}
                                aria-label={t('pricing::admin_offers.filters.sort')}
                                className={SWITCH_TRACK}
                                data-test="discounts-sort"
                            >
                                {SORTS.map((each) => (
                                    <ToggleGroupItem key={each} value={each} className={SWITCH_ITEM}>
                                        {t(`pricing::admin_offers.filters.${each}`)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </div>
                        {filtered ? (
                            <Button type="button" variant="ghost" onClick={() => visit({ state: null })} data-test="clear-filters">
                                {t('pricing::admin.clear_filters')}
                            </Button>
                        ) : null}
                    </CardContent>
                </Card>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="discounts-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(filtered ? 'pricing::admin_discounts.empty.filtered_title' : 'pricing::admin_discounts.empty.title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(filtered ? 'pricing::admin_discounts.empty.filtered_body' : 'pricing::admin_discounts.empty.body')}</EmptyDescription>
                        </EmptyHeader>
                        {!filtered && add !== undefined ? <EmptyContent>{cloneElement(add, { 'data-test': 'add-discount-empty' })}</EmptyContent> : null}
                    </Empty>
                ) : (
                    <>
                        <Showing shown={list.rows.length} total={total} />
                        <div className="material-base overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('pricing::admin_discounts.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('pricing::admin_discounts.column.category')}</TableHead>
                                        <TableHead>{t('pricing::admin_discounts.column.off')}</TableHead>
                                        <TableHead>{t('pricing::admin_discounts.column.lines')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.starts')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.ends')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.state')}</TableHead>
                                        <TableHead>{t('pricing::admin.column.always_wins')}</TableHead>
                                        <TableHead>{t('pricing::admin_discounts.column.left_out')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((discount) => (
                                        <TableRow key={discount.id} className="relative hover:bg-surface-sunken" data-test={`discount-${discount.id}`}>
                                            <TableCell>
                                                {/* The whole row opens the discount's page: the path's link stretches over it. */}
                                                <Link href={`/admin/category-discounts/${discount.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]">
                                                    {discount.categoryPath.join(' › ')}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                <DiscountOff discount={discount} />
                                            </TableCell>
                                            <TableCell>{t(`pricing::admin.lines.${discount.lines}`)}</TableCell>
                                            <TableCell>
                                                <Time value={discount.startsAt} focusable={false} />
                                            </TableCell>
                                            <TableCell>{discount.endsAt === null ? t('pricing::admin.no_end') : <Time value={discount.endsAt} focusable={false} />}</TableCell>
                                            <TableCell>
                                                <StateBadge state={discount.state} />
                                            </TableCell>
                                            <TableCell>{discount.alwaysWins ? <Mark tone="amber-subtle">{t('pricing::admin.badge.always_wins')}</Mark> : null}</TableCell>
                                            <TableCell>
                                                {discount.leftOut.length === 0 ? (
                                                    '—'
                                                ) : (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span tabIndex={0} className="relative z-10 tw-figure underline decoration-dotted underline-offset-4">{plural(t, locale, 'pricing::admin_discounts.products', discount.leftOut.length)}</span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>{discount.leftOutNames.join(t('pricing::admin.list_separator'))}</TooltipContent>
                                                    </Tooltip>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more('/admin/category-discounts', { ...params({}), after: next }, ['discounts', 'next', 'total'])} /> : null}
            </div>

            {adding ? (
                <Suspense fallback={null}>
                    <DiscountDialog
                        mode="add"
                        store={{ code: frame.storeCode, decimals: frame.decimals, currency: frame.currency, zone: page.storeTimezone, ceilingPercent: frame.ceilingPercent, wired: frame.wired }}
                        open
                        onOpenChange={(open) => (open ? undefined : setAdding(false))}
                        returnFocusTo={opener}
                    />
                </Suspense>
            ) : null}
        </AdminLayout>
    );
}
