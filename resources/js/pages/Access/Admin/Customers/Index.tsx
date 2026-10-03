import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Pager } from '@/components/Pager';
import { SearchField } from '@/components/SearchField';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CustomerListPage, CustomerRow } from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| G1 - customers, seen by staff (frontend.md §3.7).
|
| The customers whose home store is one of this person's, and everyone for a Super Admin. **Who
| appears is Access's answer**, asked again by the handler behind every row: this screen shows what
| it is given. A customer whose home store is switched off stays listed, flagged Off beside the
| store (access.md amendment 58(c)).
|
| The design's order count and lifetime spend are not here. They come with Sales in stage 6, and a
| column that could only ever read zero is worse than a column that is not there yet.
|
| Nothing on this screen changes anything: the actions live on one customer's page, where the
| person doing it can see who they are doing it to.
|
| shadcn's parts with Geist's rules (frontend.md §1.11): its Table; one badge per cell, its word the
| state - Email Confirmed and Phone Confirmed are columns of their own (Geist's Badge); moments
| through Geist's Relative Time Card; an Empty State when nothing matches, offering to clear the
| filters; and a pager - "1–20 of 142" between Previous and Next, the missing end left out rather
| than greyed (components/Pager). The pager's links carry the filters the server applied, so a page turn never quietly
| applies a search somebody typed and did not send.
*/

type Props = CustomerListPage;

type Filters = { search: string; status: string; type: string };

/** The query a list is asked with: only what is set, and the page only past the first. */
function query(filters: Filters, atPage = 1): Record<string, string> {
    const asked: Record<string, string> = {};

    if (filters.search !== '') asked.search = filters.search;
    if (filters.status !== '') asked.status = filters.status;
    if (filters.type !== '') asked.type = filters.type;
    if (atPage > 1) asked.page = String(atPage);

    return asked;
}

export default function Index({ customers, total, page, perPage, search, status, accountType, statuses, accountTypes }: Props) {
    const t = useTranslator();

    const [form, setForm] = useState<Filters>({
        search: search ?? '',
        status: status ?? '',
        type: accountType ?? '',
    });

    function apply(next: Filters) {
        router.get('/admin/customers', query(next), { preserveState: true });
    }

    function clear() {
        const cleared = { search: '', status: '', type: '' };
        setForm(cleared);
        apply(cleared);
    }

    const applied: Filters = { search: search ?? '', status: status ?? '', type: accountType ?? '' };
    const filtered = applied.search !== '' || applied.status !== '' || applied.type !== '';
    const lastPage = Math.max(1, Math.ceil(total / perPage));

    function pageHref(atPage: number): string {
        const asked = new URLSearchParams(query(applied, atPage)).toString();

        return asked === '' ? '/admin/customers' : `/admin/customers?${asked}`;
    }

    return (
        <AdminLayout title={t('access::customers.title')} subtitle={t('access::customers.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                <form
                    role="search"
                    aria-labelledby="filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                    className="material-base grid gap-4 p-4"
                >
                    <h2 id="filters" className="text-heading-14 text-ink">
                        {t('access::customers.filters')}
                    </h2>

                    {/* The search on a row of its own, so its helper line never pushes the row's
                        controls out of line (the audit's "fixed margin" finding). */}
                    <Field className="max-w-md">
                        <FieldLabel htmlFor="search">{t('access::customers.search')}</FieldLabel>
                        <SearchField
                            id="search"
                            label={t('access::customers.search')}
                            aria-describedby="search-helper"
                            value={form.search}
                            onValueChange={(value) => setForm({ ...form, search: value })}
                            onClear={() => apply({ ...form, search: '' })}
                        />
                        <FieldDescription id="search-helper">{t('access::customers.search_hint')}</FieldDescription>
                    </Field>

                    <div className="flex flex-wrap items-end gap-3">
                        <SelectField
                            id="type"
                            data-test="filter-type"
                            label={t('access::customers.type')}
                            className="w-44"
                            value={form.type}
                            onChange={(event) => {
                                const next = { ...form, type: event.target.value };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <NativeSelectOption value="">{t('access::customers.any')}</NativeSelectOption>
                            {accountTypes.map((value) => (
                                <NativeSelectOption key={value} value={value}>
                                    {t(`access::customers.account_type.${value}`)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>

                        <SelectField
                            id="status"
                            data-test="filter-status"
                            label={t('access::customers.status')}
                            className="w-44"
                            value={form.status}
                            onChange={(event) => {
                                const next = { ...form, status: event.target.value };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <NativeSelectOption value="">{t('access::customers.any')}</NativeSelectOption>
                            {statuses.map((value) => (
                                <NativeSelectOption key={value} value={value}>
                                    {t(`access::customers.account_status.${value}`)}
                                </NativeSelectOption>
                            ))}
                        </SelectField>

                        <div className="flex items-center gap-2">
                            <Button type="submit" data-test="apply-filters">
                                {t('access::customers.apply')}
                            </Button>
                            <Button type="button" variant="ghost" onClick={clear}>
                                {t('access::customers.clear')}
                            </Button>
                        </div>
                    </div>
                </form>

                <div aria-live="polite">
                    {customers.length === 0 ? (
                        <Empty className="material-base" data-test="customers-empty">
                            <EmptyHeader>
                                <EmptyTitle>{t(filtered ? 'access::customers.none_title' : 'access::customers.empty_title')}</EmptyTitle>
                                <EmptyDescription>{t(filtered ? 'access::customers.none' : 'access::customers.empty')}</EmptyDescription>
                            </EmptyHeader>
                            {filtered ? (
                                <EmptyContent>
                                    <Button variant="outline" onClick={clear}>
                                        {t('access::customers.clear')}
                                    </Button>
                                </EmptyContent>
                            ) : null}
                        </Empty>
                    ) : (
                        <div className="material-base overflow-hidden">
                            <Table>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('access::customers.name')}</TableHead>
                                        <TableHead>{t('access::customers.email')}</TableHead>
                                        <TableHead>{t('access::customers.type')}</TableHead>
                                        <TableHead>{t('access::customers.email_verified')}</TableHead>
                                        <TableHead>{t('access::customers.phone_verified')}</TableHead>
                                        <TableHead>{t('access::customers.home_store')}</TableHead>
                                        <TableHead>{t('access::customers.registered')}</TableHead>
                                        <TableHead>{t('access::customers.status')}</TableHead>
                                    </TableRow>
                                </TableHeader>

                                <TableBody>
                                    {customers.map((customer) => (
                                        <Row key={customer.id} customer={customer} />
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                {total === 0 ? null : (
                    <Pager
                        from={Math.min((page - 1) * perPage + 1, total)}
                        to={Math.min(page * perPage, total)}
                        total={total}
                        previousHref={page > 1 ? pageHref(page - 1) : null}
                        nextHref={page < lastPage ? pageHref(page + 1) : null}
                    />
                )}
            </div>
        </AdminLayout>
    );
}

function Row({ customer }: { customer: CustomerRow }) {
    const t = useTranslator();

    return (
        <TableRow>
            <TableCell>
                <Link href={`/admin/customers/${customer.id}`} data-test={`customer-${customer.id}`} className="font-medium text-ink hover:text-brand">
                    {customer.name}
                </Link>
            </TableCell>

            {/* An email address reads left to right inside an Arabic row; without bdi its parts
                are reordered on the screen (the staff cards' own bug, 2026-09-24). */}
            <TableCell>
                <bdi dir="ltr" className="text-ink-muted">
                    {customer.email}
                </bdi>
            </TableCell>

            <TableCell className="text-ink-muted">{t(`access::customers.account_type.${customer.accountType}`)}</TableCell>

            <TableCell>
                <Confirmed on={customer.emailVerified} />
            </TableCell>

            <TableCell>
                <Confirmed on={customer.phoneVerified} />
            </TableCell>

            {/* A customer of an off store stays listed, flagged (access.md amendment 58(c)). */}
            <TableCell className="text-ink-muted">
                <span className="inline-flex items-center gap-1.5">
                    {customer.homeStore}
                    {customer.homeStoreIsActive ? null : <StoreOffBadge />}
                </span>
            </TableCell>

            <TableCell className="text-ink-muted">
                <Time value={customer.registeredAt} />
            </TableCell>

            <TableCell>
                <Status customer={customer} />
            </TableCell>
        </TableRow>
    );
}

/** Confirmed or not, in words: green when confirmed, grey when not, and the word says it too. */
function Confirmed({ on }: { on: boolean }) {
    const t = useTranslator();

    return <Badge className={tone(on ? 'green-subtle' : 'gray-subtle')}>{t(on ? 'access::customers.confirmed' : 'access::customers.not_confirmed')}</Badge>;
}

/**
 * Active, blocked, or closing - and a closing account says so instead of its status, because that
 * is the thing somebody reading the row needs to know. The badge is one word; the day it closes is
 * said beside it.
 */
function Status({ customer }: { customer: CustomerRow }) {
    const t = useTranslator();

    if (customer.anonymized) {
        return <Badge className={tone('gray-subtle')}>{t('access::customers.anonymized')}</Badge>;
    }

    if (customer.deletionScheduledFor !== null) {
        return (
            <span className="inline-flex flex-wrap items-center gap-1.5">
                <Badge className={tone('amber-subtle')}>{t('access::customers.closing')}</Badge>
                <span className="text-copy-13 text-ink-muted">
                    <Time value={customer.deletionScheduledFor} />
                </span>
            </span>
        );
    }

    return (
        <Badge className={tone(customer.status === 'BLOCKED' ? 'red-subtle' : 'green-subtle')}>{t(`access::customers.account_status.${customer.status}`)}</Badge>
    );
}
