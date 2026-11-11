import type { ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { PhoneCards, StateBadge } from '@/components/content/list';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
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
import type { DocumentRowData, DocumentsPageData } from '@/types/generated/Modules/Content/Presentation/Http/Resource';

/*
| W7 Pages, W8 Blog's posts, W9 Projects as lists (content.md §4.4, §4.2): the store's items, last changed first, 50 at a
| time with Show More; the whole row opens the item. Pages and projects by tab, Published and Draft, each with its count,
| as Title · Address · State · Changed · By; the blog's posts, under its own tabs, as Title · Category · State ·
| Published · Changed. On a phone each row is a card.
*/

export type ListWords = { title: string; subtitle: string; new: string; empty: string; emptyBody: string };

export function DocumentList({ page, words, base, form, tabs, filter, note }: { page: DocumentsPageData; words: ListWords; base: string; form: string; tabs?: ReactNode; filter?: ReactNode; note?: ReactNode }) {
    const t = useTranslator();
    const name = useInLanguage();
    const posts = page.kind === 'post';
    const list = useLoadMore<DocumentRowData>(page.rows, (row) => row.id);
    const more = (cursor: string) => list.more(base, { store: page.storeCode, after: cursor, ...(posts ? { category: page.category ?? '' } : { tab: page.tab }) }, ['rows', 'next']);
    const restoring = useKeptDepth(list, page.next, more);
    const create = `${form}/new?store=${encodeURIComponent(page.storeCode)}`;
    const category = (row: DocumentRowData) => {
        const found = page.categories.find((each) => each.id === row.categoryId);

        return found === undefined ? t('content::admin_documents.nobody') : name(found.nameAr, found.nameEn);
    };
    const title = (row: DocumentRowData) => name(row.titleAr, row.titleEn ?? row.titleAr);
    const time = (value: string | null) => (value === null ? t('content::admin_documents.nobody') : <Time value={value} mode="absolute" focusable={false} />);

    return (
        <AdminLayout
            title={words.title}
            subtitle={words.subtitle}
            action={
                <Button asChild>
                    <Link href={create} data-test="new-document">
                        {words.new}
                    </Link>
                </Button>
            }
        >
            <div className="grid min-w-0 grid-cols-1 gap-4">
                <FormError />
                <StoreFilter stores={page.stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={page.storeCode} className="max-w-xs" />
                {note}
                {tabs ?? (
                    <div className="min-w-0 max-w-full overflow-x-auto" data-test="tabs-row">
                        <Tabs value={page.tab} onValueChange={(tab) => router.get(base, { store: page.storeCode, tab }, { preserveScroll: true })}>
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
                )}
                {filter}
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="documents-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{words.empty}</EmptyTitle>
                            <EmptyDescription>{words.emptyBody}</EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={create}>{words.new}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{words.title}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('content::admin_documents.columns.title')}</TableHead>
                                        <TableHead>{posts ? t('content::admin_documents.columns.category') : t('content::admin_documents.columns.address')}</TableHead>
                                        <TableHead>{t('content::admin_documents.columns.state')}</TableHead>
                                        {posts ? <TableHead>{t('content::admin_documents.columns.published')}</TableHead> : null}
                                        <TableHead>{t('content::admin_documents.columns.changed')}</TableHead>
                                        {posts ? null : <TableHead>{t('content::admin_documents.columns.by')}</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((row) => (
                                        <TableRow key={row.id} className="cursor-pointer" onClick={() => router.visit(`${form}/${row.id}`)} data-test={`document-${row.id}`}>
                                            <TableCell className="max-w-80 truncate text-label-14 text-ink">
                                                <Link href={`${form}/${row.id}`} onClick={(event) => event.stopPropagation()} className="hover:underline">
                                                    {title(row)}
                                                </Link>
                                            </TableCell>
                                            {posts ? (
                                                <TableCell className="max-w-48 truncate text-ink-muted">{category(row)}</TableCell>
                                            ) : (
                                                <TableCell className="max-w-64 truncate font-mono text-copy-13 text-ink-muted" dir="ltr">
                                                    {row.addressAr}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <StateBadge state={row.status} />
                                            </TableCell>
                                            {posts ? <TableCell className="whitespace-nowrap text-ink-muted">{time(row.publishedAt)}</TableCell> : null}
                                            <TableCell className="whitespace-nowrap text-ink-muted">{time(row.changedAt)}</TableCell>
                                            {posts ? null : <TableCell className="max-w-40 truncate text-ink-muted">{row.changedBy ?? t('content::admin_documents.nobody')}</TableCell>}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <PhoneCards
                            test="document"
                            cards={list.rows.map((row) => ({
                                id: row.id,
                                title: title(row),
                                badge: <StateBadge state={row.status} />,
                                lines: [
                                    posts ? (
                                        <span key="where">{category(row)}</span>
                                    ) : (
                                        <span key="where" dir="ltr" className="font-mono">
                                            {row.addressAr}
                                        </span>
                                    ),
                                    <span key="changed">{time(row.changedAt)}</span>,
                                ],
                                href: `${form}/${row.id}`,
                            }))}
                        />
                        {page.next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(page.next ?? '')} /> : null}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
