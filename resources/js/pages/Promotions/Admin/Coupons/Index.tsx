import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { SearchField } from '@/components/SearchField';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CouponStateBadge, useDiscountWords, useLocale } from '@/components/promotions/parts';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { CouponRowData, CouponsListPage } from '@/types/generated/Modules/Promotions/Presentation/Http/Resource';

/*
| M1 - Marketing › Coupons (promotions.md §4.1): the store's coupons, newest first, 50 at a time with Show
| More. Tabs by state - All, Active, Scheduled, Ended, Off - and a search by the start of a code, both in
| the address (owner's Q2). New Coupon only for somebody who may manage the store's coupons (Q16).
*/

const LIST = '/admin/coupons';
const STATES = ['ACTIVE', 'SCHEDULED', 'ENDED', 'INACTIVE'] as const;

export default function Index({ coupons, nextAt, nextId, state, code, mayManage, storeCode, stores }: CouponsListPage) {
    const t = useTranslator();
    const [text, setText] = useState(code ?? '');
    const list = useLoadMore(coupons, (row) => row.id);
    const first = useRef(true);
    const typed = text.trim();

    // The search runs once typing stops, from the first character (§3: the start of a code).
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (typed === (code ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => router.get(LIST, asked(storeCode, state, typed), { preserveState: true, preserveScroll: true, replace: true }), 300);

        return () => window.clearTimeout(timer);
        // Only the typed text starts a search; the page's own values travel with it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const filtered = state !== null || (code ?? '') !== '';
    const clear = () => {
        setText('');
        router.get(LIST, asked(storeCode, null, ''), { preserveState: true });
    };

    return (
        <AdminLayout
            title={t('promotions::admin_coupons.list.title')}
            subtitle={t('promotions::admin_coupons.list.subtitle')}
            action={
                mayManage ? (
                    <Button asChild>
                        <Link href={`${LIST}/new?store=${encodeURIComponent(storeCode)}`} data-test="new-coupon">
                            {t('promotions::admin_coupons.list.new')}
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <FormError />
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-start">
                    <SearchField
                        id="code"
                        label={t('promotions::admin_coupons.list.search')}
                        placeholder={t('promotions::admin_coupons.list.search_placeholder')}
                        value={text}
                        onValueChange={(value) => setText(value.slice(0, 30))}
                        onClear={clear}
                        data-test="search"
                    />
                    <StoreFilter stores={stores} value={storeCode} />
                </div>

                <Tabs value={state ?? 'ALL'} onValueChange={(next) => router.get(LIST, asked(storeCode, next === 'ALL' ? null : next, code ?? ''), { preserveState: true })}>
                    <TabsList data-test="state-tabs" aria-label={t('promotions::admin_coupons.list.states_label')}>
                        <TabsTrigger value="ALL">{t('promotions::admin_coupons.list.all')}</TabsTrigger>
                        {STATES.map((each) => (
                            <TabsTrigger key={each} value={each} data-test={`state-${each}`}>
                                {t(`promotions::admin_coupons.state.${each}`)}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="coupons-empty">
                        <EmptyHeader>
                            {filtered ? (
                                <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_coupons.list.none_match')}</EmptyDescription>
                            ) : (
                                <>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('promotions::admin_coupons.list.empty_title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('promotions::admin_coupons.list.empty_body')}</EmptyDescription>
                                </>
                            )}
                        </EmptyHeader>
                        {filtered ? (
                            <EmptyContent>
                                <Button type="button" variant="outline" onClick={clear} data-test="clear-filters">
                                    {t('promotions::admin_coupons.list.clear')}
                                </Button>
                            </EmptyContent>
                        ) : mayManage ? (
                            <EmptyContent>
                                <Button asChild>
                                    <Link href={`${LIST}/new?store=${encodeURIComponent(storeCode)}`} data-test="empty-new-coupon">
                                        {t('promotions::admin_coupons.list.new')}
                                    </Link>
                                </Button>
                            </EmptyContent>
                        ) : null}
                    </Empty>
                ) : (
                    <div className="material-base max-w-full overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('promotions::admin_coupons.list.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('promotions::admin_coupons.columns.code')}</TableHead>
                                    <TableHead>{t('promotions::admin_coupons.columns.name')}</TableHead>
                                    <TableHead>{t('promotions::admin_coupons.columns.discount')}</TableHead>
                                    <TableHead>{t('promotions::admin_coupons.columns.who')}</TableHead>
                                    <TableHead>{t('promotions::admin_coupons.columns.dates')}</TableHead>
                                    <TableHead className="text-end">{t('promotions::admin_coupons.columns.uses')}</TableHead>
                                    <TableHead>{t('promotions::admin_coupons.columns.state')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((coupon) => (
                                    <Row key={coupon.id} coupon={coupon} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {nextAt !== null && nextId !== null ? (
                    <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { ...asked(storeCode, state, code ?? ''), before_at: nextAt, before_id: nextId }, ['coupons', 'nextAt', 'nextId'])} />
                ) : null}
            </div>
        </AdminLayout>
    );
}

/** The address of the list as asked: the store, and the state and the code when there are. */
function asked(store: string, state: string | null, code: string): Record<string, string> {
    const query: Record<string, string> = { store };

    if (state !== null) query.state = state;
    if (code !== '') query.code = code;

    return query;
}

function Row({ coupon }: { coupon: CouponRowData }) {
    const t = useTranslator();
    const locale = useLocale();
    const discount = useDiscountWords();

    return (
        <TableRow data-test={`coupon-${coupon.code}`} className="relative hover:bg-surface-sunken">
            <TableCell>
                <Link
                    href={`${LIST}/${coupon.id}`}
                    className="font-mono text-label-14 text-ink after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50"
                >
                    <bdi dir="ltr">{coupon.code}</bdi>
                </Link>
            </TableCell>
            <TableCell className="min-w-40">{coupon.name}</TableCell>
            <TableCell className="whitespace-nowrap">{discount(coupon.discountKind, coupon.percent, coupon.amount, coupon.cap)}</TableCell>
            <TableCell className="text-ink-muted">{t(`promotions::admin_coupons.who.${coupon.eligibility}`)}</TableCell>
            {/* Start over end, one under the other: on one line the column alone pushed the table past a
                1280 px screen in English (the owner's look at the screens, 2026-10-20). */}
            <TableCell className="text-copy-13 text-ink-muted">
                <span className="relative z-10 block whitespace-nowrap">
                    <Time value={coupon.startsAt} />
                </span>
                <span className="block whitespace-nowrap">
                    {coupon.endsAt === null ? (
                        t('promotions::admin_coupons.list.no_end')
                    ) : (
                        <>
                            {t('promotions::admin_coupons.list.until')}{' '}
                            <span className="relative z-10">
                                <Time value={coupon.endsAt} />
                            </span>
                        </>
                    )}
                </span>
            </TableCell>
            <TableCell className="text-end">
                <bdi dir="ltr" className="tw-figure">
                    {coupon.maxUsesTotal === null ? figure(locale, coupon.usesTotal) : `${figure(locale, coupon.usesTotal)} / ${figure(locale, coupon.maxUsesTotal)}`}
                </bdi>
            </TableCell>
            <TableCell>
                <CouponStateBadge state={coupon.state} />
            </TableCell>
        </TableRow>
    );
}

