import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
*/

type Props = CustomerListPage;

type Filters = { search: string; status: string; type: string };

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

    function apply(next: Filters, atPage = 1) {
        const asked: Record<string, string> = {};

        if (next.search !== '') asked.search = next.search;
        if (next.status !== '') asked.status = next.status;
        if (next.type !== '') asked.type = next.type;
        if (atPage > 1) asked.page = String(atPage);

        router.get('/admin/customers', asked, { preserveState: true });
    }

    const lastPage = Math.max(1, Math.ceil(total / perPage));

    return (
        <AdminLayout title={t('access::customers.title')} subtitle={t('access::customers.subtitle')}>
            <div className="grid gap-4">
                <FormError />

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                    className="grid gap-3 rounded-lg border border-line bg-surface p-4"
                >
                    <h2 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                        {t('access::customers.filters')}
                    </h2>

                    <div className="flex flex-wrap items-end gap-3">
                        <label className="grid gap-1 text-xs text-ink-muted">
                            {t('access::customers.search')}
                            <Input
                                id="search"
                                className="w-64"
                                placeholder={t('access::customers.search_hint')}
                                value={form.search}
                                onChange={(event) => setForm({ ...form, search: event.target.value })}
                            />
                        </label>

                        <label className="grid gap-1 text-xs text-ink-muted">
                            {t('access::customers.type')}
                            <select
                                id="type"
                                data-test="filter-type"
                                value={form.type}
                                onChange={(event) => {
                                    const next = { ...form, type: event.target.value };
                                    setForm(next);
                                    apply(next);
                                }}
                                className="h-9 rounded-md border border-line bg-surface px-3 text-sm text-ink"
                            >
                                <option value="">{t('access::customers.any')}</option>
                                {accountTypes.map((value) => (
                                    <option key={value} value={value}>
                                        {t(`access::customers.account_type.${value}`)}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid gap-1 text-xs text-ink-muted">
                            {t('access::customers.status')}
                            <select
                                id="status"
                                data-test="filter-status"
                                value={form.status}
                                onChange={(event) => {
                                    const next = { ...form, status: event.target.value };
                                    setForm(next);
                                    apply(next);
                                }}
                                className="h-9 rounded-md border border-line bg-surface px-3 text-sm text-ink"
                            >
                                <option value="">{t('access::customers.any')}</option>
                                {statuses.map((value) => (
                                    <option key={value} value={value}>
                                        {t(`access::customers.account_status.${value}`)}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <Button type="submit" size="sm" data-test="apply-filters">
                            {t('access::customers.apply')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                const cleared = { search: '', status: '', type: '' };
                                setForm(cleared);
                                apply(cleared);
                            }}
                        >
                            {t('access::customers.clear')}
                        </Button>
                    </div>
                </form>

                {customers.length === 0 ? (
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('access::customers.none')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                        <table className="w-full text-sm">
                            <thead className="border-b border-line text-xs text-ink-muted">
                                <tr>
                                    <Th>{t('access::customers.name')}</Th>
                                    <Th>{t('access::customers.email')}</Th>
                                    <Th>{t('access::customers.type')}</Th>
                                    <Th>{t('access::customers.verified')}</Th>
                                    <Th>{t('access::customers.home_store')}</Th>
                                    <Th>{t('access::customers.registered')}</Th>
                                    <Th>{t('access::customers.status')}</Th>
                                </tr>
                            </thead>

                            <tbody>
                                {customers.map((customer) => (
                                    <Row key={customer.id} customer={customer} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="tw-figure text-xs text-ink-muted">
                        {t('access::customers.total', { count: total })}
                    </p>

                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={page <= 1}
                            onClick={() => apply(form, page - 1)}
                        >
                            {t('access::customers.previous')}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="next-page"
                            disabled={page >= lastPage}
                            onClick={() => apply(form, page + 1)}
                        >
                            {t('access::customers.next')}
                        </Button>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

function Th({ children }: { children: string }) {
    return <th className="px-4 py-2 text-start font-medium">{children}</th>;
}

function Row({ customer }: { customer: CustomerRow }) {
    const t = useTranslator();

    return (
        <tr className="border-b border-line last:border-0 hover:bg-surface-sunken">
            <td className="px-4 py-3">
                <Link
                    href={`/admin/customers/${customer.id}`}
                    data-test={`customer-${customer.id}`}
                    className="font-medium text-ink hover:text-brand"
                >
                    {customer.name}
                </Link>
            </td>

            {/* An email address reads left to right inside an Arabic row; without bdi its parts
                are reordered on the screen (the staff cards' own bug, 2026-09-24). */}
            <td className="px-4 py-3">
                <bdi dir="ltr" className="text-ink-muted">
                    {customer.email}
                </bdi>
            </td>

            <td className="px-4 py-3 text-ink-muted">
                {t(`access::customers.account_type.${customer.accountType}`)}
            </td>

            <td className="px-4 py-3">
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
            </td>

            <td className="px-4 py-3 text-ink-muted">{customer.homeStore}</td>

            {/* A date inside an Arabic sentence is reordered without an isolate: "منذ 2026-09-24"
                renders as "منذ 24-09-2026" (found by screenshotting it, 2026-09-24). */}
            <td className="tw-figure px-4 py-3 text-ink-muted">{isolate(customer.registeredAt)}</td>

            <td className="px-4 py-3">
                <Status customer={customer} />
            </td>
        </tr>
    );
}

/** Confirmed or not, as a mark rather than a word: two of these sit in one cell. */
function Mark({ on, label, short }: { on: boolean; label: string; short: string }) {
    const t = useTranslator();

    return (
        <span
            title={on ? label : t('access::customers.not_verified')}
            className={[
                'rounded-md px-1.5 py-0.5 text-xs',
                on ? 'bg-good-soft text-good' : 'bg-surface-sunken text-ink-muted',
            ].join(' ')}
        >
            {short}
        </span>
    );
}

/**
 * Active, blocked, or closing - and a closing account says so instead of its status, because that
 * is the thing somebody reading the row needs to know.
 */
function Status({ customer }: { customer: CustomerRow }) {
    const t = useTranslator();

    if (customer.anonymized) {
        return (
            <span className="rounded-md bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                {t('access::customers.anonymized')}
            </span>
        );
    }

    if (customer.deletionScheduledFor !== null) {
        return (
            <span className="rounded-md bg-warn-soft px-2 py-0.5 text-xs text-warn">
                {t('access::customers.deletion_pending', {
                    date: isolate(customer.deletionScheduledFor),
                })}
            </span>
        );
    }

    return (
        <span
            className={[
                'rounded-md px-2 py-0.5 text-xs',
                customer.status === 'BLOCKED' ? 'bg-bad-soft text-bad' : 'bg-good-soft text-good',
            ].join(' ')}
        >
            {t(`access::customers.account_status.${customer.status}`)}
        </span>
    );
}
