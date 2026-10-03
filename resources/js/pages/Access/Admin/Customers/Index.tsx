import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import {
    Badge,
    Button,
    EmptyState,
    Input,
    Pager,
    Select,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/geist';
import { isolate } from '@/lib/bidi';
import { useTranslator } from '@/lib/t';
import type {
    CustomerListPage,
    CustomerRow,
} from '@/types/generated/Modules/Access/Presentation/Http/Resource';

/*
| G1 - customers, seen by staff (frontend.md §3.7).
|
| The customers whose home store is one of this person's, and everyone for a Super Admin. **Who
| appears is Access's answer**, asked again by the handler behind every row: this screen shows what
| it is given.
|
| The design's order count and lifetime spend are not here. They come with Sales in stage 6, and a
| column that could only ever read zero is worse than a column that is not there yet.
|
| Nothing on this screen changes anything: the actions live on one customer's page, where the
| person doing it can see who they are doing it to.
|
| In Geist's parts (frontend.md 1.10): its Table, an Empty State when nothing matches rather than an
| empty table, and its Pager - "1–20 of 142" between Previous and Next, the missing end left out
| rather than greyed. The pager's links carry the filters the server applied, so a page turn never
| quietly applies a search somebody typed and did not send.
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

export default function Index({
    customers,
    total,
    page,
    perPage,
    search,
    status,
    accountType,
    statuses,
    accountTypes,
}: Props) {
    const t = useTranslator();

    const [form, setForm] = useState<Filters>({
        search: search ?? '',
        status: status ?? '',
        type: accountType ?? '',
    });

    function apply(next: Filters) {
        router.get('/admin/customers', query(next), { preserveState: true });
    }

    const applied: Filters = { search: search ?? '', status: status ?? '', type: accountType ?? '' };
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
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                    className="material-base grid gap-3 p-4"
                >
                    <h2 className="text-heading-14 text-ink">{t('access::customers.filters')}</h2>

                    <div className="flex flex-wrap items-start gap-3">
                        <Input
                            id="search"
                            label={t('access::customers.search')}
                            helper={t('access::customers.search_hint')}
                            value={form.search}
                            onChange={(event) => setForm({ ...form, search: event.target.value })}
                            className="w-64"
                        />

                        <Select
                            id="type"
                            data-test="filter-type"
                            label={t('access::customers.type')}
                            value={form.type}
                            onChange={(event) => {
                                const next = { ...form, type: event.target.value };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <option value="">{t('access::customers.any')}</option>
                            {accountTypes.map((value) => (
                                <option key={value} value={value}>
                                    {t(`access::customers.account_type.${value}`)}
                                </option>
                            ))}
                        </Select>

                        <Select
                            id="status"
                            data-test="filter-status"
                            label={t('access::customers.status')}
                            value={form.status}
                            onChange={(event) => {
                                const next = { ...form, status: event.target.value };
                                setForm(next);
                                apply(next);
                            }}
                        >
                            <option value="">{t('access::customers.any')}</option>
                            {statuses.map((value) => (
                                <option key={value} value={value}>
                                    {t(`access::customers.account_status.${value}`)}
                                </option>
                            ))}
                        </Select>

                        {/* Level with the fields rather than with the helper text under the search:
                            down by a label and its gap (20 + 6 px), then as tall as a field. */}
                        <div className="mt-6.5 flex h-9 items-center gap-2">
                            <Button typeName="submit" size="small" data-test="apply-filters">
                                {t('access::customers.apply')}
                            </Button>

                            <Button
                                type="tertiary"
                                size="small"
                                onClick={() => {
                                    const cleared = { search: '', status: '', type: '' };
                                    setForm(cleared);
                                    apply(cleared);
                                }}
                            >
                                {t('access::customers.clear')}
                            </Button>
                        </div>
                    </div>
                </form>

                {customers.length === 0 ? (
                    <EmptyState title={t('access::customers.none_title')} description={t('access::customers.none')} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('access::customers.name')}</TableHead>
                                <TableHead>{t('access::customers.email')}</TableHead>
                                <TableHead>{t('access::customers.type')}</TableHead>
                                <TableHead>{t('access::customers.verified')}</TableHead>
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
                )}

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
        <TableRow className="hover:bg-surface-sunken">
            <TableCell>
                <Link
                    href={`/admin/customers/${customer.id}`}
                    data-test={`customer-${customer.id}`}
                    className="font-medium text-ink hover:text-brand"
                >
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

            <TableCell className="text-ink-muted">
                {t(`access::customers.account_type.${customer.accountType}`)}
            </TableCell>

            <TableCell>
                <span className="flex flex-wrap gap-1">
                    <Mark
                        on={customer.emailVerified}
                        label={t('access::customers.email_verified')}
                        short={t('access::customers.email')}
                    />
                    <Mark
                        on={customer.phoneVerified}
                        label={t('access::customers.phone_verified')}
                        short={t('access::customers.phone')}
                    />
                </span>
            </TableCell>

            <TableCell className="text-ink-muted">{customer.homeStore}</TableCell>

            {/* A date inside an Arabic sentence is reordered without an isolate: "منذ 2026-09-24"
                renders as "منذ 24-09-2026" (found by screenshotting it, 2026-09-24). */}
            <TableCell className="tw-figure text-ink-muted">{isolate(customer.registeredAt)}</TableCell>

            <TableCell>
                <Status customer={customer} />
            </TableCell>
        </TableRow>
    );
}

/**
 * Confirmed or not, as a small badge rather than a sentence: two of these sit in one cell. Green
 * when confirmed, grey when not - and the word and its title say it too, never the colour alone.
 */
function Mark({ on, label, short }: { on: boolean; label: string; short: string }) {
    const t = useTranslator();

    return (
        <Badge variant={on ? 'green-subtle' : 'gray-subtle'} size="small" title={on ? label : t('access::customers.not_verified')}>
            {short}
        </Badge>
    );
}

/**
 * Active, blocked, or closing - and a closing account says so instead of its status, because that
 * is the thing somebody reading the row needs to know.
 */
function Status({ customer }: { customer: CustomerRow }) {
    const t = useTranslator();

    if (customer.anonymized) {
        return <Badge variant="gray-subtle">{t('access::customers.anonymized')}</Badge>;
    }

    if (customer.deletionScheduledFor !== null) {
        return (
            <Badge variant="amber-subtle">
                {t('access::customers.deletion_pending', {
                    date: isolate(customer.deletionScheduledFor),
                })}
            </Badge>
        );
    }

    return (
        <Badge variant={customer.status === 'BLOCKED' ? 'red-subtle' : 'green-subtle'}>
            {t(`access::customers.account_status.${customer.status}`)}
        </Badge>
    );
}
