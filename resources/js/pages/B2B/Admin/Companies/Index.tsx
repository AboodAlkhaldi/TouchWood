import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Pager } from '@/components/Pager';
import { SearchField } from '@/components/SearchField';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { StaffCompanyListPage, StaffCompanyRowData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { companyStatusTone } from '../../status';

/*
| The staff company list (b2b.md §3.2, §4.6, amendments 21 and 23), on shadcn's parts with Geist's
| rules (frontend.md §1.11).
|
| The companies of the reader's stores - who appears is B2B's answer (ListCompanies) - waiting ones
| first, the oldest sent first, then the rest by their latest status change; 25 a page. The filters
| go into the address, so a refresh or a shared link lands on the same list. Nothing here changes
| anything: **the whole row opens the company** (owner, 2026-10-04, as the roles list), its name the
| real link for a keyboard and a screen reader. Times are moments in the store being worked in
| (amendment 23(b)), relative in the cells, the full moment on hover or focus (Geist's Table), raised
| above the row's link so it can be reached.
*/

type Filters = { search: string; status: string; store: string };

const LIST = '/admin/companies';

export default function Index({ companies, total, page, perPage, search, status, storeId, statuses, stores }: StaffCompanyListPage) {
    const t = useTranslator();
    const [form, setForm] = useState<Filters>({ search: search ?? '', status: status ?? '', store: storeId ?? '' });

    const filtered = (search ?? '') !== '' || (status ?? '') !== '' || (storeId ?? '') !== '';

    function query(next: Filters, atPage = 1): Record<string, string> {
        const asked: Record<string, string> = {};

        if (next.search.trim() !== '') asked.search = next.search.trim();
        if (next.status !== '') asked.status = next.status;
        if (next.store !== '') asked.store = next.store;
        if (atPage > 1) asked.page = String(atPage);

        return asked;
    }

    function apply(next: Filters) {
        router.get(LIST, query(next), { preserveState: true });
    }

    function href(atPage: number): string {
        const params = new URLSearchParams(query({ search: search ?? '', status: status ?? '', store: storeId ?? '' }, atPage));
        const text = params.toString();

        return text === '' ? LIST : `${LIST}?${text}`;
    }

    const clear = () => {
        const cleared = { search: '', status: '', store: '' };
        setForm(cleared);
        apply(cleared);
    };

    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);

    return (
        <AdminLayout title={t('b2b::admin_companies.title')} subtitle={t('b2b::admin_companies.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                <Card className="material-base gap-0 border-0 py-0">
                    <form
                        aria-label={t('b2b::admin_companies.filters')}
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply(form);
                        }}
                    >
                        <CardContent className="grid gap-4 p-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            {/* Geist's Search Input: a scoped placeholder, and its helper tied to it. */}
                            <Field>
                                <FieldLabel htmlFor="search">{t('b2b::admin_companies.search')}</FieldLabel>
                                <SearchField
                                    id="search"
                                    label={t('b2b::admin_companies.search')}
                                    placeholder={t('b2b::admin_companies.search_placeholder')}
                                    value={form.search}
                                    onValueChange={(value) => setForm({ ...form, search: value })}
                                    onClear={() => apply({ ...form, search: '' })}
                                    aria-describedby="search-helper"
                                    data-test="filter-search"
                                />
                                <FieldDescription id="search-helper" className="text-copy-13 text-ink-muted">
                                    {t('b2b::admin_companies.search_helper')}
                                </FieldDescription>
                            </Field>

                            <SelectField
                                id="status"
                                label={t('b2b::admin_companies.status_filter')}
                                value={form.status}
                                onChange={(event) => {
                                    const next = { ...form, status: event.target.value };
                                    setForm(next);
                                    apply(next);
                                }}
                                data-test="filter-status"
                            >
                                <NativeSelectOption value="">{t('b2b::admin_companies.all_statuses')}</NativeSelectOption>
                                {statuses.map((value) => (
                                    <NativeSelectOption key={value} value={value}>
                                        {t(`b2b::admin_companies.status.${value}`)}
                                    </NativeSelectOption>
                                ))}
                            </SelectField>

                            {/* Only the reader's own stores, and none to choose between when they have
                                one: filtering by another store is refused (amendment 10(j)). */}
                            {stores.length > 1 ? (
                                <SelectField
                                    id="store"
                                    label={t('b2b::admin_companies.store_filter')}
                                    value={form.store}
                                    onChange={(event) => {
                                        const next = { ...form, store: event.target.value };
                                        setForm(next);
                                        apply(next);
                                    }}
                                    data-test="filter-store"
                                >
                                    <NativeSelectOption value="">{t('b2b::admin_companies.all_stores')}</NativeSelectOption>
                                    {stores.map((store) => (
                                        <NativeSelectOption key={store.id} value={store.id}>
                                            {store.name}
                                        </NativeSelectOption>
                                    ))}
                                </SelectField>
                            ) : null}
                        </CardContent>
                        <CardFooter className="justify-end gap-2 border-t border-line bg-surface-sunken px-4 py-3 [.border-t]:pt-3">
                            {filtered ? (
                                <Button type="button" variant="ghost" onClick={clear} data-test="clear-filters">
                                    {t('b2b::admin_companies.clear')}
                                </Button>
                            ) : null}
                            <Button type="submit" variant="outline" data-test="apply-filters">
                                {t('b2b::admin_companies.search_button')}
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                {companies.length === 0 ? (
                    <Empty className="material-base" data-test="companies-empty">
                        <EmptyHeader>
                            {total > 0 ? (
                                // An address past the last page: the list is not empty, this page is.
                                <>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('b2b::admin_companies.past_end.title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('b2b::admin_companies.past_end.body')}</EmptyDescription>
                                </>
                            ) : filtered ? (
                                <>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('b2b::admin_companies.no_match.title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('b2b::admin_companies.no_match.body')}</EmptyDescription>
                                </>
                            ) : (
                                <>
                                    <EmptyTitle className="text-heading-16 text-ink">{t('b2b::admin_companies.empty.title')}</EmptyTitle>
                                    <EmptyDescription className="text-copy-14 text-ink-muted">{t('b2b::admin_companies.empty.body')}</EmptyDescription>
                                </>
                            )}
                        </EmptyHeader>
                        {total > 0 ? (
                            <EmptyContent>
                                <Button asChild variant="outline">
                                    <Link href={href(1)} data-test="first-page">
                                        {t('b2b::admin_companies.past_end.first')}
                                    </Link>
                                </Button>
                            </EmptyContent>
                        ) : filtered ? (
                            <EmptyContent>
                                <Button type="button" variant="outline" onClick={clear}>
                                    {t('b2b::admin_companies.clear')}
                                </Button>
                            </EmptyContent>
                        ) : null}
                    </Empty>
                ) : (
                    <div className="material-base overflow-hidden">
                        <Table>
                            <TableCaption className="sr-only">{t('b2b::admin_companies.title')}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t('b2b::admin_companies.column.company')}</TableHead>
                                    <TableHead>{t('b2b::admin_companies.column.status')}</TableHead>
                                    <TableHead>{t('b2b::admin_companies.column.store')}</TableHead>
                                    <TableHead>{t('b2b::admin_companies.column.sent')}</TableHead>
                                    <TableHead>{t('b2b::admin_companies.column.changed')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {companies.map((company) => (
                                    <Row key={company.id} company={company} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {companies.length > 0 ? <Pager from={from} to={to} total={total} previousHref={page > 1 ? href(page - 1) : null} nextHref={to < total ? href(page + 1) : null} /> : null}
            </div>
        </AdminLayout>
    );
}

/** A value that is not there: Geist's em dash. */
function Unknown() {
    return <span className="text-ink-subtle">—</span>;
}

function Row({ company }: { company: StaffCompanyRowData }) {
    const t = useTranslator();

    return (
        // The name's link stretches over the whole row (the owner's choice, as the roles list); the
        // warning mark sits above it, so its tooltip can still be reached.
        <TableRow data-test={`company-row-${company.id}`} className="relative hover:bg-surface-sunken">
            <TableCell>
                <span className="inline-flex flex-wrap items-center gap-2">
                    <Link
                        href={`${LIST}/${company.id}`}
                        data-test={`company-${company.id}`}
                        className="font-medium text-ink after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50"
                    >
                        {company.name}
                    </Link>
                    {/* A mark, not a second badge in the row (Geist's badge rules): the status is the
                        row's one badge. A real button, so the reason can be reached by keyboard. */}
                    {company.typeDeactivatedSinceSent ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-xs"
                                    aria-label={t('b2b::admin_companies.type_deactivated')}
                                    className="relative z-10 text-warn"
                                    data-test="type-deactivated"
                                >
                                    <AlertTriangle aria-hidden="true" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{t('b2b::admin_companies.type_deactivated_tooltip')}</TooltipContent>
                        </Tooltip>
                    ) : null}
                </span>
            </TableCell>
            <TableCell>
                <Badge className={tone(companyStatusTone(company.status))} data-test="company-status">
                    {t(`b2b::admin_companies.status.${company.status}`)}
                </Badge>
            </TableCell>
            <TableCell className="text-ink-muted">{company.storeName}</TableCell>
            {/* Above the row's link, so the full moment opens on hover and on focus (Geist's Table);
                a moment there is none of is an em dash. */}
            <TableCell className="text-copy-13 text-ink-muted">
                {company.waitingSince === null ? <Unknown /> : <span className="relative z-10"><Time value={company.waitingSince} /></span>}
            </TableCell>
            <TableCell className="text-copy-13 text-ink-muted">
                {company.statusChangedAt === null ? <Unknown /> : <span className="relative z-10"><Time value={company.statusChangedAt} /></span>}
            </TableCell>
        </TableRow>
    );
}
