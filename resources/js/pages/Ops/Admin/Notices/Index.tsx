import { useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { channelWords, Reached, ReadBackDialog, ReadBackLine, RefreshWhileSending, SentAt, WrittenStatus, type ReadBack } from '@/components/ops/written-list';
import { PanelDialog } from '@/components/PanelDialog';
import { PhoneCards } from '@/components/PhoneCards';
import { MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyContent, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useKeptDepth } from '@/lib/kept-depth';
import { zoneName } from '@/lib/moments';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { NoticeData, NoticesListPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| O5 · Offer Notices (ops.md §4.15; Q13, Q15, Q16): the store's notices, newest written first. Pressing a row reads it
| back; while it waits its menu edits or cancels it, and one that started meanwhile says so in a toast as the list re-reads.
*/

const LIST = '/admin/notices';

export default function Index({ notices, next, stores, storeCode, storeTimezone }: NoticesListPageData) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const { locale } = usePage<SharedProps>().props;
    const list = useLoadMore<NoticeData>(notices, (notice) => notice.id);
    const more = (cursor: string) => list.more(LIST, { store: storeCode, after: cursor }, ['notices', 'next']);
    // A cancel sends back the first page: the rows shown with Show More are asked for again, so the row stays, Cancelled.
    const restoring = useKeptDepth(list, next, more);
    const table = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState<NoticeData | null>(null);
    const [cancelling, setCancelling] = useState<NoticeData | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);
    const newNotice = `${LIST}/new?store=${encodeURIComponent(storeCode)}`;
    // A draft has no time (ops.md §9.7 #1): it goes with a campaign.
    const when = (notice: NoticeData) =>
        notice.sendAtLocal === null ? (
            <span data-test="with-campaign">{t('ops::admin_written.notices.with_campaign')}</span>
        ) : (
            <bdi dir="ltr" className="tw-figure">
                {notice.sendAtLocal}
            </bdi>
        );

    const audience = (notice: NoticeData): string => (notice.segmentId === null ? t('ops::admin_written.audience.every') : inLanguage(notice.segmentNameAr, notice.segmentNameEn) || t('ops::admin_written.audience.deleted_segment'));

    const readBack = (notice: NoticeData): ReadBack => ({
        title: inLanguage(notice.titleAr, notice.titleEn),
        audience: audience(notice),
        holds: notice.segmentId === null ? null : notice.segmentMemberCount,
        row: notice,
        lines: (
            <>
                <ReadBackLine label={t('ops::admin_written.read_back.link')}>
                    {notice.link === null ? (
                        t('ops::admin_written.read_back.no_link')
                    ) : (
                        <bdi dir="ltr" data-test="read-back-link">
                            {notice.link}
                        </bdi>
                    )}
                </ReadBackLine>
                <ReadBackLine label={t('ops::admin_written.read_back.sends')}>
                    {when(notice)} {notice.sendAtLocal === null ? null : <span className="text-copy-13 text-ink-muted">{zoneName(storeTimezone, locale)}</span>}
                </ReadBackLine>
                <ReadBackLine label={t('ops::admin_written.read_back.written_by')}>{notice.writtenBy}</ReadBackLine>
                {notice.startedAt === null ? null : (
                    <ReadBackLine label={t('ops::admin_written.read_back.sent')}>
                        <SentAt value={notice.startedAt} />
                    </ReadBackLine>
                )}
            </>
        ),
    });

    return (
        <AdminLayout
            title={t('ops::admin_written.notices.title')}
            subtitle={t('ops::admin_written.notices.subtitle')}
            action={
                <Button asChild>
                    <Link href={newNotice} data-test="new-notice">
                        {t('ops::admin_written.notices.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <StoreFilter stores={stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={storeCode} className="max-w-xs" />
                <RefreshWhileSending rows={list.rows} only={['notices', 'next']} />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="notices-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('ops::admin_written.notices.empty')}</EmptyTitle>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={newNotice}>{t('ops::admin_written.notices.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div ref={table} tabIndex={-1} className="material-base hidden max-w-full overflow-x-auto sm:block">
                            <Table>
                                <TableCaption className="sr-only">{t('ops::admin_written.notices.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('ops::admin_written.notices.columns.title')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.audience')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.by')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.status')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.sends')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.reached')}</TableHead>
                                        <TableHead>{t('ops::admin_written.notices.columns.written_by')}</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('ops::admin_written.notices.columns.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((notice) => (
                                        <TableRow key={notice.id} className="cursor-pointer" onClick={() => setOpen(notice)} data-test={`notice-${notice.id}`}>
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="link"
                                                    className="h-auto max-w-64 justify-start truncate p-0 text-label-14"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        setOpen(notice);
                                                    }}
                                                    data-test="open"
                                                >
                                                    {inLanguage(notice.titleAr, notice.titleEn)}
                                                </Button>
                                            </TableCell>
                                            <TableCell className="text-ink" data-test="audience">
                                                {audience(notice)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{channelWords(notice, t)}</TableCell>
                                            <TableCell>
                                                <WrittenStatus status={notice.status} />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted" data-test="sends">
                                                {notice.status === 'SENT' ? t('ops::admin_written.notices.sent') : when(notice)}
                                            </TableCell>
                                            <TableCell>
                                                <Reached row={notice} />
                                            </TableCell>
                                            <TableCell className="text-ink-muted">{notice.writtenBy}</TableCell>
                                            <TableCell className="text-end" onClick={(event) => event.stopPropagation()}>
                                                {notice.changeable ? (
                                                    <RowActions
                                                        notice={notice}
                                                        name={inLanguage(notice.titleAr, notice.titleEn)}
                                                        onCancel={(from) => {
                                                            opener.current = from;
                                                            setCancelling(notice);
                                                        }}
                                                        test={`more-${notice.id}`}
                                                    />
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {/* On a phone each notice a card (round 3 #15); pressing it reads it back, its menu at the end. */}
                        <PhoneCards
                            test="notice"
                            cards={list.rows.map((notice) => ({
                                id: notice.id,
                                title: inLanguage(notice.titleAr, notice.titleEn),
                                badge: <WrittenStatus status={notice.status} />,
                                lines: [
                                    <span key="audience">{`${t('ops::admin_written.notices.columns.audience')}: ${audience(notice)}`}</span>,
                                    <span key="sends">
                                        {`${t('ops::admin_written.notices.columns.sends')}: `}
                                        {notice.status === 'SENT' ? (
                                            t('ops::admin_written.notices.sent')
                                        ) : notice.sendAtLocal === null ? (
                                            t('ops::admin_written.notices.with_campaign')
                                        ) : (
                                            <bdi dir="ltr" className="tw-figure">
                                                {notice.sendAtLocal}
                                            </bdi>
                                        )}
                                    </span>,
                                    <span key="written">{`${t('ops::admin_written.notices.columns.written_by')}: ${notice.writtenBy}`}</span>,
                                ],
                                onOpen: () => setOpen(notice),
                                menu: notice.changeable ? (
                                    <RowActions
                                        notice={notice}
                                        name={inLanguage(notice.titleAr, notice.titleEn)}
                                        onCancel={(from) => {
                                            opener.current = from;
                                            setCancelling(notice);
                                        }}
                                        test={`more-card-${notice.id}`}
                                    />
                                ) : undefined,
                            }))}
                        />
                        {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>

            {open === null ? null : <ReadBackDialog open={readBack(open)} onClose={() => setOpen(null)} />}

            {cancelling === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(next) => (next ? undefined : setCancelling(null))}
                    returnFocusTo={opener}
                    title={t('ops::admin_written.notices.cancel.title')}
                    description={t('ops::admin_written.notices.cancel.body')}
                    busy={busy}
                    cancelLabel={t('ops::admin_written.notices.cancel.keep')}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(
                                    `${LIST}/${cancelling.id}/cancel`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        // The row's ⋯ button goes when it is cancelled: the focus goes back to the table.
                                        onSuccess: () => {
                                            opener.current = table.current;
                                        },
                                        onFinish: () => {
                                            setBusy(false);
                                            setCancelling(null);
                                        },
                                    },
                                )
                            }
                            data-test="confirm-cancel-notice"
                        >
                            {t('ops::admin_written.notices.cancel.confirm')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            )}
        </AdminLayout>
    );
}

/** A waiting notice's ⋯ menu: the row's, and the same on its phone card under its own test name. */
function RowActions({ notice, name, onCancel, test }: { notice: NoticeData; name: string; onCancel: (from: HTMLElement | null) => void; test: string }) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test={test} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                <DropdownMenuItem asChild>
                    <Link href={`${LIST}/${notice.id}/edit`} data-test="edit-notice">
                        {t('ops::admin_written.notices.menu.edit')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => onCancel(more.current)} data-test="cancel-notice">{`${t('ops::admin_written.notices.menu.cancel')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
