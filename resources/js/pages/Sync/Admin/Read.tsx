import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Time, useMoments } from '@/components/Time';
import { Description, type DescriptionItem } from '@/components/geist-only/Description';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { figure } from '@/lib/digits';
import { useLoadMore } from '@/lib/use-load-more';
import type { ReadLineData, ReportedData, SyncReadPage } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';
import { Detail, EmptyState, FailureText, KindBadge, type Links, nameIn, R, ReadStateBadge, sizeName, took, useLocale, useT, What } from './parts';

/*
| **A read** (sync.md §4.1 Y2, `/admin/sync/reads/{id}?section=&page=`): its header - kind, state, who asked,
| when it was queued, started and finished, how long it took, its counts, a failed read's reason as Y1 writes
| it - and its **sections as tabs** (Q32), each in the address and paged on its own, 100 at a time with Show
| More: Prices Changed and Stock Changed (from → to), Reported (as it was that day, §9.3 #22) and, for a
| Notice or One Code read, Codes Asked. A read pruned after 90 days, another store's or never one is the
| not-found page, which the server answers (§9.3 #29).
*/

const SECTIONS = ['PRICES', 'STOCK', 'REPORTED', 'CODES'] as const;
type Section = (typeof SECTIONS)[number];

/** One row of the section shown, whatever its section: keyed by its place, so a size twice is drawn twice. */
type Row = { key: string; line?: ReadLineData; reported?: ReportedData; code?: string };

export default function Read(page: SyncReadPage) {
    const { read, sections } = page;
    const t = useT();
    const locale = useLocale();
    const moments = useMoments();
    const [switching, setSwitching] = useState(false);
    const section = (SECTIONS as readonly string[]).includes(page.section) ? (page.section as Section) : 'PRICES';
    // Codes Asked belongs to a read that named its codes: a Notice or One Code read.
    const shown = SECTIONS.filter((each) => each !== 'CODES' || read.codes !== null);
    const counts: Record<Section, number> = { PRICES: sections.prices, STOCK: sections.stock, REPORTED: sections.reported, CODES: sections.codes };
    const links: Links = { store: page.storeCode, mayViewProducts: page.mayViewProducts, mayViewOffers: page.mayViewOffers };
    const url = `/admin/sync/reads/${read.id}`;
    const title = t(`${R}.read.title`, { date: moments.full(read.queuedAt) });

    const items = useMemo<Row[]>(() => {
        const at = `${page.section}:${page.page}`;

        if (section === 'CODES') {
            return page.codes.map((code, index) => ({ key: `${at}:${index}`, code }));
        }

        if (section === 'REPORTED') {
            return page.reported.map((reported, index) => ({ key: `${at}:${index}`, reported }));
        }

        return page.lines.map((line, index) => ({ key: `${at}:${index}`, line }));
    }, [page.section, page.page, page.codes, page.reported, page.lines, section]);
    const list = useLoadMore<Row>(items, (row) => row.key);

    const choose = (value: string) =>
        router.get(url, { section: value.toLowerCase() }, { preserveScroll: true, replace: true, onStart: () => setSwitching(true), onFinish: () => setSwitching(false) });

    const none = t(`${R}.reads.none`);
    const H = `${R}.read.header`;
    const header: DescriptionItem[] = [
        { title: t(`${H}.kind`), content: t(`${R}.read_kinds.${read.kind}`), 'data-test': 'read-kind' },
        { title: t(`${H}.state`), content: <ReadStateBadge state={read.state} />, 'data-test': 'read-state' },
        { title: t(`${H}.asked_by`), content: read.askedBySystem ? t(`${R}.the_system`) : read.askedBy, 'data-test': 'read-asked-by' },
        { title: t(`${H}.queued`), content: <Time value={read.queuedAt} mode="absolute" /> },
        { title: t(`${H}.started`), content: read.startedAt === null ? none : <Time value={read.startedAt} mode="absolute" /> },
        { title: t(`${H}.finished`), content: read.finishedAt === null ? none : <Time value={read.finishedAt} mode="absolute" /> },
        { title: t(`${H}.took`), content: <span className="tw-figure">{took(t, locale, read.tookSeconds)}</span> },
        {
            title: t(`${H}.counts`),
            content: (
                <span className="tw-figure" data-test="read-counts">
                    {[
                        `${t(`${R}.read.counts_parts.prices`)} ${figure(locale, read.pricesChanged)}`,
                        `${t(`${R}.read.counts_parts.stock`)} ${figure(locale, read.stockChanged)}`,
                        `${t(`${R}.read.counts_parts.skipped`)} ${figure(locale, read.skipped)}`,
                    ].join(' · ')}
                </span>
            ),
        },
        ...(read.failure === null ? [] : [{ title: t(`${H}.reason`), content: <FailureText failure={read.failure} />, 'data-test': 'read-reason' }]),
    ];

    return (
        <AdminLayout
            title={title}
            breadcrumbs={[
                { label: t(`${R}.title`), href: `/admin/sync?store=${page.storeCode}` },
                { label: page.storeCode, href: `/admin/sync?store=${page.storeCode}&tab=reads` },
            ]}
        >
            <div className="grid gap-6">
                <FormError />

                <Card className="material-base gap-0 border-0 py-0" data-test="read-header">
                    <CardContent className="px-5 py-4">
                        <Description items={header} columns={3} />
                    </CardContent>
                </Card>

                <Tabs value={section} onValueChange={choose} activationMode="manual" className="min-w-0 gap-4">
                    <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={title}>
                        {shown.map((each) => (
                            <TabsTrigger key={each} value={each} data-test={`section-${each}`} className="flex-none gap-2 px-3 text-label-14">
                                {t(`${R}.read.sections.${each}`)}
                                <span className="tw-figure text-copy-12 text-ink-muted">{figure(locale, counts[each])}</span>
                            </TabsTrigger>
                        ))}
                    </TabsList>

                    {switching ? (
                        <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                            <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                            {t('ui.loading')}
                        </p>
                    ) : (
                        <TabsContent value={section} className="grid gap-4">
                            {list.rows.length === 0 ? (
                                <EmptyState title={t(`${R}.read.sections.${section}`)} body={t(`${R}.read.empty`)} test="section-empty" />
                            ) : (
                                <div className="material-base overflow-x-auto">
                                    <Table>
                                        <TableCaption className="sr-only">{t(`${R}.read.sections.${section}`)}</TableCaption>
                                        {section === 'CODES' ? <CodesRows rows={list.rows} /> : section === 'REPORTED' ? <ReportedRows rows={list.rows} links={links} /> : <LineRows rows={list.rows} links={links} />}
                                    </Table>
                                </div>
                            )}
                            {page.more ? (
                                <LoadMoreButton loading={list.loading} onClick={() => list.more(url, { section: section.toLowerCase(), page: page.page + 1 }, ['lines', 'reported', 'codes', 'page', 'more'])} />
                            ) : null}
                        </TabsContent>
                    )}
                </Tabs>
            </div>
        </AdminLayout>
    );
}

const C = `${R}.read.columns`;

/** Prices Changed and Stock Changed: Product · Size · Code · From → To, each already written by the server. */
function LineRows({ rows, links }: { rows: Row[]; links: Links }) {
    const t = useT();
    const locale = useLocale();

    return (
        <>
            <TableHeader className="bg-surface-sunken">
                <TableRow>
                    <TableHead>{t(`${C}.product`)}</TableHead>
                    <TableHead>{t(`${C}.size`)}</TableHead>
                    <TableHead>{t(`${C}.code`)}</TableHead>
                    <TableHead>{t(`${C}.from_to`)}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map(({ key, line }) =>
                    line === undefined ? null : (
                        <TableRow key={key} data-test={`line-${line.variantId}`}>
                            <TableCell>
                                {!line.sizeExists || line.productId === null ? (
                                    <span className="text-copy-14 text-ink-muted">{t(`${R}.problems.size_gone`)}</span>
                                ) : links.mayViewProducts ? (
                                    <Link href={`/admin/products/${line.productId}?tab=stores`} className="text-label-14 text-ink underline-offset-4 hover:underline">
                                        {nameIn(locale, line.productNameAr, line.productNameEn)}
                                    </Link>
                                ) : (
                                    <span className="text-label-14 text-ink">{nameIn(locale, line.productNameAr, line.productNameEn)}</span>
                                )}
                            </TableCell>
                            <TableCell>{line.sizeExists ? sizeName(t, line.values) : '—'}</TableCell>
                            <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                                {line.code ?? '—'}
                            </TableCell>
                            <TableCell>
                                <span className="inline-flex items-center gap-1.5" data-test="from-to">
                                    <bdi dir="ltr" className="tw-figure">
                                        {line.before ?? '—'}
                                    </bdi>
                                    {/* "from → to": mirrored on Arabic pages, read out as "to" (pricing.md §4.3.1). */}
                                    <ArrowRight aria-hidden="true" className="inline size-3.5 rtl:rotate-180" />
                                    <span className="sr-only">{t(`${C}.to`)}</span>
                                    <bdi dir="ltr" className="tw-figure">
                                        {line.after}
                                    </bdi>
                                </span>
                            </TableCell>
                        </TableRow>
                    ),
                )}
            </TableBody>
        </>
    );
}

/** Reported, as it was that day (§9.3 #22): Kind · What · Detail, as the Sync Report writes them. */
function ReportedRows({ rows, links }: { rows: Row[]; links: Links }) {
    const t = useT();

    return (
        <>
            <TableHeader className="bg-surface-sunken">
                <TableRow>
                    <TableHead>{t(`${R}.problems.columns.kind`)}</TableHead>
                    <TableHead>{t(`${R}.problems.columns.what`)}</TableHead>
                    <TableHead>{t(`${R}.problems.columns.detail`)}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map(({ key, reported }) =>
                    reported === undefined ? null : (
                        <TableRow key={key} data-test="reported-row">
                            <TableCell>
                                <KindBadge kind={reported.kind} />
                            </TableCell>
                            <TableCell>
                                <What about={reported} links={links} />
                            </TableCell>
                            <TableCell className="max-w-md text-copy-14 whitespace-normal">
                                <Detail about={reported} links={links} />
                            </TableCell>
                        </TableRow>
                    ),
                )}
            </TableBody>
        </>
    );
}

/** Codes Asked: the codes a Notice or One Code read named, as written (mono). */
function CodesRows({ rows }: { rows: Row[] }) {
    const t = useT();

    return (
        <>
            <TableHeader className="bg-surface-sunken">
                <TableRow>
                    <TableHead>{t(`${C}.code`)}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map(({ key, code }) => (
                    <TableRow key={key} data-test="code-row">
                        <TableCell className="tw-figure font-mono text-copy-13" dir="ltr">
                            {code}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </>
    );
}
