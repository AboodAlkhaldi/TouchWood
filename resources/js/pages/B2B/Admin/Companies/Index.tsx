import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Search } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import {
    Badge,
    Button,
    ButtonLink,
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
    Tooltip,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type {
    StaffCompanyListPage,
    StaffCompanyRowData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { companyStatusLook, FormNote, when } from '../shared';

/*
| The staff company list (b2b.md §3.2, §4.6, amendment 19).
|
| The companies of the reader's stores - who appears is B2B's answer (ListCompanies) - waiting ones
| first, the oldest sent first, then the rest by their latest status change; 25 a page. The filters
| go into the address, so a refresh or a shared link lands on the same list. Nothing here changes
| anything: each row opens one company, where the decisions are.
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

    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);

    return (
        <AdminLayout title={t('b2b::admin_companies.title')} subtitle={t('b2b::admin_companies.subtitle')}>
            <div className="grid gap-4">
                <FormNote />

                <form
                    aria-label={t('b2b::admin_companies.filters')}
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(form);
                    }}
                    className="material-base grid gap-2 p-4"
                >
                    <div className="flex flex-wrap items-end gap-3">
                    <Input
                        id="search"
                        type="search"
                        className="w-full sm:w-72"
                        label={t('b2b::admin_companies.search')}
                        placeholder={t('b2b::admin_companies.search_placeholder')}
                        prefix={<Search aria-hidden="true" className="size-4" />}
                        value={form.search}
                        onChange={(event) => setForm({ ...form, search: event.target.value })}
                        data-test="filter-search"
                    />

                    <Select
                        id="status"
                        className="w-full sm:w-48"
                        label={t('b2b::admin_companies.status_filter')}
                        value={form.status}
                        onChange={(event) => {
                            const next = { ...form, status: event.target.value };
                            setForm(next);
                            apply(next);
                        }}
                        data-test="filter-status"
                    >
                        <option value="">{t('b2b::admin_companies.all_statuses')}</option>
                        {statuses.map((value) => (
                            <option key={value} value={value}>
                                {t(`b2b::admin_companies.status.${value}`)}
                            </option>
                        ))}
                    </Select>

                    {/* Only the reader's own stores, and none to choose between when they have one:
                        filtering by another store is refused (amendment 10(j)). */}
                    {stores.length > 1 ? (
                        <Select
                            id="store"
                            className="w-full sm:w-48"
                            label={t('b2b::admin_companies.store_filter')}
                            value={form.store}
                            onChange={(event) => {
                                const next = { ...form, store: event.target.value };
                                setForm(next);
                                apply(next);
                            }}
                            data-test="filter-store"
                        >
                            <option value="">{t('b2b::admin_companies.all_stores')}</option>
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>
                                    {store.name}
                                </option>
                            ))}
                        </Select>
                    ) : null}

                    <div className="flex gap-2">
                        <Button typeName="submit" type="secondary" data-test="apply-filters">
                            {t('b2b::admin_companies.search_button')}
                        </Button>
                        {filtered ? (
                            <Button
                                type="tertiary"
                                onClick={() => {
                                    const cleared = { search: '', status: '', store: '' };
                                    setForm(cleared);
                                    apply(cleared);
                                }}
                                data-test="clear-filters"
                            >
                                {t('b2b::admin_companies.clear')}
                            </Button>
                        ) : null}
                    </div>
                    </div>
                    <p className="text-copy-13 text-ink-muted">{t('b2b::admin_companies.search_helper')}</p>
                </form>

                {companies.length === 0 ? (
                    total > 0 ? (
                        // An address past the last page: the list is not empty, this page is.
                        <EmptyState
                            title={t('b2b::admin_companies.past_end.title')}
                            description={t('b2b::admin_companies.past_end.body')}
                            actions={
                                <ButtonLink type="secondary" href={href(1)} data-test="first-page">
                                    {t('b2b::admin_companies.past_end.first')}
                                </ButtonLink>
                            }
                            data-test="companies-empty"
                        />
                    ) : filtered ? (
                        <EmptyState
                            title={t('b2b::admin_companies.no_match.title')}
                            description={t('b2b::admin_companies.no_match.body')}
                            data-test="companies-empty"
                        />
                    ) : (
                        <EmptyState
                            title={t('b2b::admin_companies.empty.title')}
                            description={t('b2b::admin_companies.empty.body')}
                            data-test="companies-empty"
                        />
                    )
                ) : (
                    <Table aria-label={t('b2b::admin_companies.title')}>
                        <TableHeader>
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
                )}

                {companies.length > 0 ? (
                    <Pager
                        from={from}
                        to={to}
                        total={total}
                        previousHref={page > 1 ? href(page - 1) : null}
                        nextHref={to < total ? href(page + 1) : null}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}

function Row({ company }: { company: StaffCompanyRowData }) {
    const t = useTranslator();

    return (
        <TableRow data-test={`company-row-${company.id}`} className="hover:bg-surface-sunken">
            <TableCell>
                <span className="inline-flex flex-wrap items-center gap-2">
                    <Link href={`${LIST}/${company.id}`} data-test={`company-${company.id}`} className="font-medium text-ink hover:text-brand">
                        {company.name}
                    </Link>
                    {/* A mark, not a second badge in the row (Geist's badge rules): the status is the
                        row's one badge. */}
                    {company.typeDeactivatedSinceSent ? (
                        <Tooltip text={t('b2b::admin_companies.type_deactivated_tooltip')}>
                            <span
                                tabIndex={0}
                                role="img"
                                aria-label={t('b2b::admin_companies.type_deactivated')}
                                className="inline-flex text-warn"
                                data-test="type-deactivated"
                            >
                                <AlertTriangle aria-hidden="true" className="size-4" />
                            </span>
                        </Tooltip>
                    ) : null}
                </span>
            </TableCell>
            <TableCell>
                <Badge variant={companyStatusLook(company.status)} data-test="company-status">
                    {t(`b2b::admin_companies.status.${company.status}`)}
                </Badge>
            </TableCell>
            <TableCell className="text-ink-muted">{company.storeName}</TableCell>
            <TableCell className="tw-figure text-ink-muted">
                {company.waitingSince === null ? null : <bdi dir="ltr">{when(company.waitingSince)}</bdi>}
            </TableCell>
            <TableCell className="tw-figure text-ink-muted">
                {company.statusChangedAt === null ? null : <bdi dir="ltr">{when(company.statusChangedAt)}</bdi>}
            </TableCell>
        </TableRow>
    );
}
