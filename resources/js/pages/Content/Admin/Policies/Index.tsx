import { Link, router } from '@inertiajs/react';
import { StateBadge } from '@/components/content/list';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PhoneCards } from '@/components/PhoneCards';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { useKeptDepth } from '@/lib/kept-depth';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { PoliciesPageData, PolicyRowData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| Content › Policies (flags-2026-11.md S3.7): tabs Published and Draft, each with its count; the policies serving the
| person's stores, last changed first, 50 at a time with Show More, as Title · Kind · Stores · Version · Changed · By;
| the whole row opens the policy. On a phone each row is a card. One list for every store: a policy serves several.
*/

const LIST = '/admin/content/policies';

export default function Index(page: PoliciesPageData) {
    const t = useTranslator();
    const name = useInLanguage();
    const list = useLoadMore<PolicyRowData>(page.rows, (row) => row.id);
    const more = (cursor: string) => list.more(LIST, { tab: page.tab, after: cursor }, ['rows', 'next']);
    const restoring = useKeptDepth(list, page.next, more);
    const nobody = t('content::admin_documents.nobody');
    const title = (row: PolicyRowData) => name(row.titleAr, row.titleEn ?? row.titleAr);
    const kind = (row: PolicyRowData) => t(`content::policies.kinds.${row.kind.toLowerCase()}`);
    const stores = (row: PolicyRowData) => row.stores.join(t('content::admin_policies.list_separator'));
    const version = (row: PolicyRowData) => (row.textVersion === 0 ? nobody : t('content::admin_policies.fields.version', { number: row.textVersion }));
    const time = (value: string | null) => (value === null ? nobody : <Time value={value} mode="absolute" focusable={false} />);

    return (
        <AdminLayout
            title={t('content::admin_policies.title')}
            subtitle={t('content::admin_policies.subtitle')}
            action={
                <Button asChild>
                    <Link href={`${LIST}/new`} data-test="new-policy">
                        {t('content::admin_policies.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <div className="min-w-0 max-w-full overflow-x-auto" data-test="tabs-row">
                    <Tabs value={page.tab} onValueChange={(tab) => router.get(LIST, { tab }, { preserveScroll: true })}>
                        <TabsList variant="line">
                            <TabsTrigger value="published" data-test="tab-published">
                                {t('content::admin_documents.tabs.published')}
                                <span className="tw-figure text-ink-muted">{page.counts.PUBLISHED ?? 0}</span>
                            </TabsTrigger>
                            <TabsTrigger value="draft" data-test="tab-draft">
                                {t('content::admin_documents.tabs.draft')}
                                <span className="tw-figure text-ink-muted">{page.counts.DRAFT ?? 0}</span>
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="policies-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('content::admin_policies.empty')}</EmptyTitle>
                            <EmptyDescription>{t('content::admin_policies.empty_body')}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={`${LIST}/new`}>{t('content::admin_policies.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('content::admin_policies.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_documents.columns.title')}</TableHead>
                                        <TableHead>{t('content::admin_policies.columns.kind')}</TableHead>
                                        <TableHead>{t('content::admin_policies.columns.stores')}</TableHead>
                                        <TableHead>{t('content::admin_policies.columns.version')}</TableHead>
                                        <TableHead>{t('content::admin_documents.columns.changed')}</TableHead>
                                        <TableHead>{t('content::admin_documents.columns.by')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`${LIST}/${row.id}`)} data-test={`policy-${row.id}`}>
                                            <TableCell className="max-w-80 truncate text-label-14 text-ink">
                                                <Link href={`${LIST}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                                                    {title(row)}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="max-w-48 truncate text-ink-muted" data-test="kind">
                                                {kind(row)}
                                            </TableCell>
                                            <TableCell className="max-w-56 truncate text-ink-muted" data-test="stores">
                                                {stores(row)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted" data-test="version">
                                                {version(row)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{time(row.changedAt)}</TableCell>
                                            <TableCell className="max-w-40 truncate text-ink-muted">{row.changedBy ?? nobody}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="policy"
                            cards={list.rows.map((row) => ({
                                id: row.id,
                                title: title(row),
                                badge: <StateBadge state={row.status} />,
                                lines: [<span key="kind">{`${kind(row)} · ${version(row)}`}</span>, <span key="stores">{stores(row)}</span>, <span key="changed">{time(row.changedAt)}</span>],
                                href: `${LIST}/${row.id}`,
                            }))}
                        />
                        {page.next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(page.next ?? '')} /> : null}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
