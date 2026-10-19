import { type RefObject, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Note } from '@/components/Note';
import { PanelDialog } from '@/components/PanelDialog';
import { Guarded, MoreButton } from '@/components/RowMenu';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { figure } from '@/lib/digits';
import { useKeptDepth } from '@/lib/kept-depth';
import { useLoadMore } from '@/lib/use-load-more';
import type { ProblemsData, ReadRowData, ReadsData, ReportEntryData, SyncFrameData, SyncReportPage, SyncStatusData } from '@/types/generated/Modules/Sync/Presentation/Http/Resource';
import { Detail, EmptyState, FailureText, KindBadge, type Links, MARK, Phrase, R, ReadStateBadge, Showing, StoreFrame, StoreStateBadge, took, useLocale, useT, What } from './parts';

/*
| **The Sync Report** (sync.md §4.1 Y1, `?store=&tab=problems|reads&kind=`): the status line above the tabs -
| the provider, its state, the last read and the last full read, a read waiting or running; Failing in a red
| Note with its reason -; **Problems** (the open entries, the latest seen first, a kind filter, each row's ⋯
| by its kind: Retry Read, Open Offer or Open Product, then Dismiss Entry…) and **Reads** (every read kept,
| newest first, each row opening its page, Y2). **Sync Now** is the main action for whoever runs reads in a
| wired store, disabled only while a Now read already waits (Q26). A store not wired shows its Reads only,
| read-only, with Wire Store for a Super Admin (Q35).
*/

const TABS = ['problems', 'reads'] as const;

export default function Report(page: SyncReportPage) {
    const { storeFrame: frame, status, tab } = page;
    const t = useT();
    const locale = useLocale();
    const wired = status.state !== 'NOT_WIRED';
    const [syncing, setSyncing] = useState(false);
    const [switching, setSwitching] = useState(false);
    const links: Links = { store: frame.storeCode, mayViewProducts: frame.mayViewProducts, mayViewOffers: frame.mayViewOffers };
    const open = page.problems?.allOpen ?? page.reads?.openEntries ?? 0;

    const syncNow =
        frame.mayRun && wired ? (
            <ActionButton
                type="button"
                loading={syncing}
                disabledReason={frame.syncNowRefusal ?? undefined}
                onClick={() => router.post('/admin/sync/now', { store: frame.storeCode }, { preserveScroll: true, onStart: () => setSyncing(true), onFinish: () => setSyncing(false) })}
                data-test="sync-now"
            >
                {t(`${R}.sync_now`)}
            </ActionButton>
        ) : undefined;

    // A tab is opened by its address, as a product's tabs are: the server reads the open one only.
    const choose = (value: string) =>
        router.get('/admin/sync', { store: frame.storeCode, tab: value }, { preserveScroll: true, replace: true, onStart: () => setSwitching(true), onFinish: () => setSwitching(false) });

    return (
        <AdminLayout title={t(`${R}.title`)} subtitle={t(`${R}.subtitle`)} action={syncNow}>
            <div className="grid gap-4">
                <FormError />
                <StoreFrame frame={frame} />
                <StatusLine status={status} frame={frame} />

                {!wired ? (
                    // Not wired: no Problems tab - the reads kept, read-only (Q35).
                    page.reads === null ? null : <ReadsList reads={page.reads} frame={frame} />
                ) : (
                    <Tabs value={tab} onValueChange={choose} activationMode="manual" className="min-w-0 gap-4">
                        <TabsList variant="line" className="h-10 w-full max-w-full min-w-0 justify-start overflow-x-auto border-b border-line p-0" aria-label={t(`${R}.title`)}>
                            {TABS.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`tab-${each}`} className="flex-none gap-2 px-3 text-label-14">
                                    {t(`${R}.tabs.${each}`)}
                                    {each === 'problems' && open > 0 ? (
                                        <span className="tw-figure text-copy-12 text-ink-muted" data-test="problems-count">
                                            {figure(locale, open)}
                                        </span>
                                    ) : null}
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        {switching ? (
                            <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                                <Spinner aria-hidden="true" role={undefined} aria-label={undefined} />
                                {t('ui.loading')}
                            </p>
                        ) : (
                            <TabsContent value={tab} className="grid gap-4">
                                {tab === 'problems' && page.problems !== null ? <ProblemsList problems={page.problems} kind={page.kind} frame={frame} links={links} /> : null}
                                {tab === 'reads' && page.reads !== null ? <ReadsList reads={page.reads} frame={frame} /> : null}
                            </TabsContent>
                        )}
                    </Tabs>
                )}
            </div>
        </AdminLayout>
    );
}

/**
 * **The status line** (`SyncStatus`): "Odoo · Working · Last read 12 minutes ago · Last full read …", and a
 * read running or waiting; Failing in a red Note; Not Wired with Wire Store for a Super Admin (Q35).
 */
function StatusLine({ status, frame }: { status: SyncStatusData; frame: SyncFrameData }) {
    const t = useT();
    const moment = (at: string) => <Time value={at} inSentence />;

    return (
        <div className="grid gap-3">
            <Card className="material-base gap-0 border-0 py-0" role="group" aria-label={t(`${R}.status.label`)} data-test="status-line">
                <CardContent className="grid gap-2 px-5 py-4 text-copy-14 text-ink">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        {status.providerName === null ? null : <span className="text-label-14">{status.providerName}</span>}
                        <StoreStateBadge state={status.state} />
                        {status.state === 'NOT_WIRED' ? null : (
                            <>
                                <span data-test="last-read">
                                    {status.lastReadAt === null ? t(`${R}.status.never_read`) : <Phrase text={t(`${R}.status.last_read`, { time: MARK })} moment={moment(status.lastReadAt)} />}
                                </span>
                                {status.lastFullReadAt === null ? null : (
                                    <span className="text-ink-muted" data-test="last-full-read">
                                        <Phrase text={t(`${R}.status.last_full_read`, { time: MARK })} moment={moment(status.lastFullReadAt)} />
                                    </span>
                                )}
                            </>
                        )}
                    </div>
                    {status.running !== null ? (
                        <p className="text-ink-muted" data-test="read-running">
                            <Phrase text={t(`${R}.status.running`, { time: MARK })} moment={moment(status.running.at)} />
                        </p>
                    ) : status.waiting !== null ? (
                        <p className="text-ink-muted" data-test="read-waiting">
                            <Phrase text={t(`${R}.status.waiting`, { time: MARK })} moment={moment(status.waiting.at)} />
                        </p>
                    ) : null}
                </CardContent>
            </Card>

            {status.state === 'FAILING' ? (
                <Note variant="error" data-test="failing">
                    <span className="grid gap-1">
                        {status.failure === null ? null : <FailureText failure={status.failure} />}
                        <span>{t(`${R}.status.press_sync_now`)}</span>
                    </span>
                </Note>
            ) : null}

            {status.state === 'NOT_WIRED' ? (
                <Note
                    variant="secondary"
                    action={
                        frame.mayWire ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={`/admin/sync/provider?store=${frame.storeCode}`} data-test="wire-store">
                                    {t(`${R}.wire_store`)}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    data-test="not-wired"
                >
                    {t(`${R}.status.not_wired`)}
                </Note>
            ) : null}
        </div>
    );
}

/** **Problems**: the open entries, 50 at a time, the latest seen first, with the kind filter. */
function ProblemsList({ problems, kind, frame, links }: { problems: ProblemsData; kind: string | null; frame: SyncFrameData; links: Links }) {
    const t = useT();
    const locale = useLocale();
    const list = useLoadMore<ReportEntryData>(problems.entries, (entry) => String(entry.id));
    const params = (change: Record<string, string | null>) => {
        const all: Record<string, string | null> = { store: frame.storeCode, tab: 'problems', kind: kind === null ? null : kind.toLowerCase(), ...change };

        return Object.fromEntries(Object.entries(all).filter((entry): entry is [string, string] => entry[1] !== null && entry[1] !== ''));
    };
    const more = (cursor: string) => list.more('/admin/sync', params({ after: cursor }), ['problems']);
    // Retry and Dismiss send the page back: the rows Show More had shown are asked for again.
    const restoring = useKeptDepth(list, problems.next, more);
    const [dismissing, setDismissing] = useState<ReportEntryData | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const opener = useRef<HTMLElement | null>(null);

    return (
        <>
            <div className="flex flex-wrap items-end gap-3">
                <SelectField
                    id="kind-filter"
                    label={t(`${R}.problems.columns.kind`)}
                    value={kind ?? ''}
                    onChange={(event) => router.get('/admin/sync', params({ kind: event.target.value === '' ? null : event.target.value.toLowerCase() }), { preserveState: true, preserveScroll: true })}
                    className="max-w-xs"
                    data-test="kind-filter"
                >
                    <NativeSelectOption value="">{t(`${R}.kinds.all`)}</NativeSelectOption>
                    {problems.kinds.map((each) => (
                        <NativeSelectOption key={each.kind} value={each.kind}>
                            {t(`${R}.kind_count`, { kind: t(`${R}.kinds.${each.kind}`), count: figure(locale, each.count) })}
                        </NativeSelectOption>
                    ))}
                </SelectField>
            </div>

            {list.rows.length === 0 ? (
                <EmptyState title={t(`${R}.problems.empty.title`)} body={t(`${R}.problems.empty.body`)} test="problems-empty" />
            ) : (
                <>
                    <Showing shown={list.rows.length} total={problems.total} />
                    <div className="material-base overflow-x-auto">
                        <Table>
                            <TableCaption className="sr-only">{t(`${R}.tabs.problems`)}</TableCaption>
                            <TableHeader className="bg-surface-sunken">
                                <TableRow>
                                    <TableHead>{t(`${R}.problems.columns.kind`)}</TableHead>
                                    <TableHead>{t(`${R}.problems.columns.what`)}</TableHead>
                                    <TableHead>{t(`${R}.problems.columns.detail`)}</TableHead>
                                    <TableHead>{t(`${R}.problems.columns.first_seen`)}</TableHead>
                                    <TableHead>{t(`${R}.problems.columns.last_seen`)}</TableHead>
                                    {frame.mayRun ? (
                                        <TableHead className="w-12">
                                            <span className="sr-only">{t(`${R}.problems.columns.actions`)}</span>
                                        </TableHead>
                                    ) : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {list.rows.map((entry) => (
                                    <TableRow key={entry.id} data-test={`entry-${entry.id}`}>
                                        <TableCell>
                                            <KindBadge kind={entry.about.kind} />
                                        </TableCell>
                                        <TableCell>
                                            <What about={entry.about} links={links} />
                                        </TableCell>
                                        <TableCell className="max-w-md text-copy-14 whitespace-normal">
                                            <Detail about={entry.about} links={links} />
                                        </TableCell>
                                        <TableCell>
                                            <Time value={entry.firstSeenAt} />
                                        </TableCell>
                                        <TableCell>
                                            <Time value={entry.lastSeenAt} />
                                        </TableCell>
                                        {frame.mayRun ? (
                                            <TableCell className="text-end">
                                                <EntryMenu
                                                    entry={entry}
                                                    frame={frame}
                                                    busy={busy === entry.id}
                                                    onRetry={() =>
                                                        router.post(`/admin/sync/entries/${entry.id}/retry`, {}, { preserveScroll: true, onStart: () => setBusy(entry.id), onFinish: () => setBusy(null) })
                                                    }
                                                    onDismiss={(trigger) => {
                                                        opener.current = trigger;
                                                        setDismissing(entry);
                                                    }}
                                                />
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}

            {problems.next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(problems.next ?? '')} /> : null}

            {dismissing === null ? null : <DismissDialog entry={dismissing} onOpenChange={(next) => (next ? undefined : setDismissing(null))} returnFocusTo={opener} />}
        </>
    );
}

/**
 * An entry's ⋯ (Y1, `sync.read.run`): **Retry Read** - every kind but Offer Stopped and Off or Draft, which a
 * read never closes and which offer **Open Offer** / **Open Product** instead (Q27); a size deleted since
 * has it out of reach -; then **Dismiss Entry…** (Q28), out of reach on a failed read's entry.
 */
function EntryMenu({ entry, frame, busy, onRetry, onDismiss }: { entry: ReportEntryData; frame: SyncFrameData; busy: boolean; onRetry: () => void; onDismiss: (trigger: HTMLElement | null) => void }) {
    const t = useT();
    const more = useRef<HTMLButtonElement>(null);
    const { about } = entry;
    const M = `${R}.problems.menu`;
    const name = `${t(`${R}.kinds.${about.kind}`)}${about.code === null ? '' : ` ${about.code}`}`;
    const neverRead = about.kind === 'SALE_STOPPED' || about.kind === 'OFF_OR_DRAFT_PRICED';
    const offer = about.kind === 'SALE_STOPPED' && frame.mayViewOffers && about.offer !== null && about.offer.exists ? about.offer : null;
    const product = about.kind === 'OFF_OR_DRAFT_PRICED' && frame.mayViewProducts && about.sizeExists === true && about.productId !== null ? about.productId : null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} busy={busy} data-test={`entry-actions-${entry.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                {!neverRead ? <Guarded label={t(`${M}.retry`)} reason={about.sizeExists === false ? frame.reasons.sizeGone : null} onSelect={onRetry} test="retry-entry" /> : null}
                {offer !== null ? <Guarded label={t(`${M}.open_offer`)} onSelect={() => router.visit(`/admin/offers?store=${frame.storeCode}&sale=${offer.id}`)} test="open-offer" /> : null}
                {product !== null ? <Guarded label={t(`${M}.open_product`)} onSelect={() => router.visit(`/admin/products/${product}?tab=stores`)} test="open-product" /> : null}
                {!neverRead || offer !== null || product !== null ? <DropdownMenuSeparator /> : null}
                <Guarded label={t(`${M}.dismiss`)} reason={about.kind === 'READ_FAILED' ? frame.reasons.readFailedStays : null} onSelect={() => onDismiss(more.current)} test="dismiss-entry" />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Dismiss Entry: asked first; it leaves the report, and comes back if a read finds it again. */
function DismissDialog({ entry, onOpenChange, returnFocusTo }: { entry: ReportEntryData; onOpenChange: (open: boolean) => void; returnFocusTo: RefObject<HTMLElement | null> }) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const D = `${R}.problems.dismiss`;

    return (
        <PanelDialog
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${D}.title`)}
            description={t(`${D}.body`)}
            busy={busy}
            confirm={
                <ActionButton
                    loading={busy}
                    onClick={() =>
                        router.post(`/admin/sync/entries/${entry.id}/dismiss`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })
                    }
                    data-test="confirm-dismiss"
                >
                    {t(`${D}.confirm`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}

/** **Reads**: every read kept (90 days), newest first, 50 at a time; a row opens the read's page (Y2). */
function ReadsList({ reads, frame }: { reads: ReadsData; frame: SyncFrameData }) {
    const t = useT();
    const locale = useLocale();
    const list = useLoadMore<ReadRowData>(reads.reads, (read) => read.id);
    const more = (cursor: string) => list.more('/admin/sync', { store: frame.storeCode, tab: 'reads', after: cursor }, ['reads']);
    // Sync Now sends the page back: the rows Show More had shown are asked for again.
    const restoring = useKeptDepth(list, reads.next, more);
    const none = t(`${R}.reads.none`);
    const C = `${R}.reads.columns`;

    if (list.rows.length === 0) {
        return <EmptyState title={t(`${R}.reads.empty.title`)} body={t(`${R}.reads.empty.body`)} test="reads-empty" />;
    }

    return (
        <div className="grid gap-4">
            <Showing shown={list.rows.length} total={reads.total} />
            <div className="material-base overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">{t(`${R}.tabs.reads`)}</TableCaption>
                    <TableHeader className="bg-surface-sunken">
                        <TableRow>
                            <TableHead>{t(`${C}.kind`)}</TableHead>
                            <TableHead>{t(`${C}.state`)}</TableHead>
                            <TableHead>{t(`${C}.asked_by`)}</TableHead>
                            <TableHead>{t(`${C}.started`)}</TableHead>
                            <TableHead>{t(`${C}.took`)}</TableHead>
                            <TableHead>{t(`${C}.prices`)}</TableHead>
                            <TableHead>{t(`${C}.stock`)}</TableHead>
                            <TableHead>{t(`${C}.skipped`)}</TableHead>
                            <TableHead>{t(`${C}.codes`)}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {list.rows.map((read) => (
                            <TableRow key={read.id} className="relative hover:bg-surface-sunken" data-test={`read-${read.id}`}>
                                <TableCell>
                                    {/* The whole row opens the read's page: its kind's link stretches over it. */}
                                    <Link href={`/admin/sync/reads/${read.id}`} className="text-label-14 text-ink after:absolute after:inset-0 focus-visible:after:ring-[3px]" data-test="open-read">
                                        {t(`${R}.read_kinds.${read.kind}`)}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <ReadStateBadge state={read.state} />
                                </TableCell>
                                <TableCell>{read.askedBySystem ? t(`${R}.the_system`) : read.askedBy}</TableCell>
                                <TableCell>{read.startedAt === null ? none : <Time value={read.startedAt} focusable={false} />}</TableCell>
                                <TableCell className="tw-figure">{took(t, locale, read.tookSeconds)}</TableCell>
                                <TableCell className="tw-figure">{figure(locale, read.pricesChanged)}</TableCell>
                                <TableCell className="tw-figure">{figure(locale, read.stockChanged)}</TableCell>
                                <TableCell className="tw-figure">{figure(locale, read.skipped)}</TableCell>
                                <TableCell className="tw-figure">{read.codes === null ? none : figure(locale, read.codes)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {reads.next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(reads.next ?? '')} /> : null}
        </div>
    );
}
