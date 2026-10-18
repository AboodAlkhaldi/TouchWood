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
import { Field, FieldDescription } from '@/components/ui/field';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import { Amount, ExpiringCell, Points, ProgrammeOffNote, Unknown } from '@/pages/Loyalty/Admin/Points/parts';
import type { PointHolderData, PointHoldersPage } from '@/types/generated/Modules/Loyalty/Presentation/Http/Resource';

/*
| L1 - Points › Customers (loyalty.md §4.1): who holds points in the store the filter shows, most live
| points first, 50 at a time with Show More (answer 13).
|
| The search (answer 2) runs 300 ms after typing stops and lives in the address. It reaches names in
| every store, emails and phones only among the customers this reader may view (access.md amendment
| 72(b)). A search that matches too many customers says so and lists none (§9.5 P2): a short list
| in points order would look complete. Under three characters it searches nothing.
|
| A row opens the customer's points in this store - the whole row is the link, as Geist's tables do,
| reachable by keyboard through the name.
*/

const LIST = '/admin/points/customers';
const SHORTEST = 3;
const LONGEST = 100;

export default function Customers({ holders, nextPoints, nextAccountId, tooMany, search, storeCode, programmeOn, stores }: PointHoldersPage) {
    const t = useTranslator();
    const [text, setText] = useState(search ?? '');
    const list = useLoadMore(holders, (holder) => holder.accountId);
    const typed = text.trim();
    const short = typed !== '' && typed.length < SHORTEST;
    const first = useRef(true);

    // The search runs once typing stops, and only for something it can search (3 to 100 characters, or
    // nothing at all to clear it); the address keeps it.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (short || typed.length > LONGEST || typed === (search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(LIST, asked(storeCode, typed), { preserveState: true, preserveScroll: true, replace: true });
        }, 300);

        return () => window.clearTimeout(timer);
        // Only the typed text starts a search; the page's own values travel with it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typed]);

    const clear = () => {
        setText('');
        router.get(LIST, asked(storeCode, ''), { preserveState: true });
    };

    const searching = (search ?? '') !== '';

    return (
        <AdminLayout title={t('loyalty::admin_points.customers.title')} subtitle={t('loyalty::admin_points.customers.subtitle')}>
            <div className="grid gap-4">
                <FormError />
                <ProgrammeOffNote on={programmeOn} />

                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-start">
                    <Field>
                        <SearchField
                            id="search"
                            label={t('loyalty::admin_points.search.label')}
                            placeholder={t('loyalty::admin_points.search.placeholder')}
                            value={text}
                            onValueChange={(value) => setText(value.slice(0, LONGEST))}
                            onClear={clear}
                            aria-describedby={short ? 'search-helper' : undefined}
                            data-test="search"
                        />
                        {short ? (
                            <FieldDescription id="search-helper" className="text-copy-13 text-ink-muted" data-test="search-short">
                                {t('loyalty::admin_points.search.too_short')}
                            </FieldDescription>
                        ) : null}
                    </Field>
                    <StoreFilter stores={stores} value={storeCode} />
                </div>

                {tooMany ? (
                    <Empty className="material-base" data-test="too-many">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::admin_points.search.too_many')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="holders-empty">
                        <EmptyHeader>
                            {searching ? (
                                <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::admin_points.search.no_match', { text: search ?? '' })}</EmptyDescription>
                            ) : (
                                <>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('loyalty::admin_points.empty.holders_title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('loyalty::admin_points.empty.holders_body')}</EmptyDescription>
                                </>
                            )}
                        </EmptyHeader>
                        {searching ? (
                            <EmptyContent>
                                <Button type="button" variant="outline" onClick={clear} data-test="clear-search">
                                    {t('loyalty::admin_points.search.clear')}
                                </Button>
                            </EmptyContent>
                        ) : null}
                    </Empty>
                ) : (
                    // Wide tables scroll inside their own box on a phone, never the page (frontend.md).
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t('loyalty::admin_points.customers.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('loyalty::admin_points.columns.customer')}</TableHead>
                                    <TableHead className="text-end">{t('loyalty::admin_points.columns.points')}</TableHead>
                                    <TableHead className="text-end">{t('loyalty::admin_points.columns.worth')}</TableHead>
                                    <TableHead>{t('loyalty::admin_points.columns.expiring')}</TableHead>
                                    <TableHead>{t('loyalty::admin_points.columns.last_change')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((holder) => (
                                    <Row key={holder.accountId} holder={holder} storeCode={storeCode} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {!tooMany && nextPoints !== null && nextAccountId !== null ? (
                    <LoadMoreButton
                        loading={list.loading}
                        onClick={() => list.more(LIST, { ...asked(storeCode, search ?? ''), after_points: nextPoints, after_account: nextAccountId }, ['holders', 'nextPoints', 'nextAccountId'])}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}

/** The address of the list as asked: the store, and the search when there is one. */
function asked(store: string, search: string): Record<string, string> {
    return search === '' ? { store } : { store, search };
}

function Row({ holder, storeCode }: { holder: PointHolderData; storeCode: string }) {
    const t = useTranslator();
    const href = `${LIST}/${holder.customer.id}?store=${encodeURIComponent(storeCode)}`;

    return (
        <TableRow data-test={`holder-${holder.customer.id}`} className="relative hover:bg-surface-sunken">
            <TableCell className="min-w-48">
                {/* A deleted customer has no name and no link (answer 7). */}
                {holder.customer.deleted ? (
                    <span className="text-ink-muted">{t('loyalty::admin_points.deleted_customer')}</span>
                ) : (
                    <Link href={href} className="font-medium text-ink after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50">
                        <bdi>{holder.customer.name}</bdi>
                    </Link>
                )}
                {holder.customer.email === null ? null : (
                    <div className="text-copy-13 text-ink-muted">
                        <bdi dir="ltr">{holder.customer.email}</bdi>
                    </div>
                )}
            </TableCell>
            <TableCell className="text-end">
                <Points value={holder.points} />
            </TableCell>
            <TableCell className="text-end whitespace-nowrap">
                <Amount value={holder.worth} />
            </TableCell>
            <TableCell className="text-copy-13 whitespace-nowrap text-ink-muted">
                <ExpiringCell expiring={holder.expiring} />
            </TableCell>
            {/* Above the row's link, so the full moment opens on hover and on focus (Geist's Table). */}
            <TableCell className="text-copy-13 text-ink-muted">
                {holder.lastAt === null ? <Unknown /> : <span className="relative z-10"><Time value={holder.lastAt} /></span>}
            </TableCell>
        </TableRow>
    );
}
