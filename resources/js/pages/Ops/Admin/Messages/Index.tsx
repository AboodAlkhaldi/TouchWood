import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { channelWords, Reached, ReadBackDialog, ReadBackLine, RefreshWhileSending, SentAt, WrittenStatus, type ReadBack } from '@/components/ops/written-list';
import { FormError } from '@/components/FormError';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Button } from '@/components/ui/button';
import { Empty, EmptyContent, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useInLanguage } from '@/lib/in-language';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { MessagesListPageData, StaffMessageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';

/*
| O3 · Customer Messages (ops.md §4.15; Q13, Q15, Q16): what staff sent, newest first, one store at a time. Pressing a row
| reads it back with no new read; while any says Sending, Refresh reads the list again and nothing does it by itself.
*/

const LIST = '/admin/messages';

export default function Index({ messages, next, stores, storeCode }: MessagesListPageData) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const list = useLoadMore<StaffMessageData>(messages, (message) => message.id);
    const [open, setOpen] = useState<StaffMessageData | null>(null);
    const newMessage = `${LIST}/new?store=${encodeURIComponent(storeCode)}`;

    const audience = (message: StaffMessageData): string => {
        if (message.audience === 'ONE') {
            return message.customerName || t('ops::admin_written.audience.unknown_customer');
        }

        if (message.audience === 'STORE') {
            return t('ops::admin_written.audience.every');
        }

        return inLanguage(message.segmentNameAr, message.segmentNameEn) || t('ops::admin_written.audience.deleted_segment');
    };

    const readBack = (message: StaffMessageData): ReadBack => ({
        title: inLanguage(message.titleAr, message.titleEn),
        audience: audience(message),
        holds: message.audience === 'SEGMENT' ? message.segmentMemberCount : null,
        row: message,
        lines: (
            <>
                <ReadBackLine label={t('ops::admin_written.read_back.sent_by')}>{message.sentBy}</ReadBackLine>
                <ReadBackLine label={t('ops::admin_written.read_back.sent')}>
                    <SentAt value={message.createdAt} />
                </ReadBackLine>
            </>
        ),
    });

    return (
        <AdminLayout
            title={t('ops::admin_written.messages.title')}
            subtitle={t('ops::admin_written.messages.subtitle')}
            action={
                <Button asChild>
                    <Link href={newMessage} data-test="new-message">
                        {t('ops::admin_written.messages.new')}
                    </Link>
                </Button>
            }
        >
            <div className="grid gap-4">
                <FormError />
                <StoreFilter stores={stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={storeCode} className="max-w-xs" />
                <RefreshWhileSending rows={list.rows} only={['messages', 'next']} />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="messages-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('ops::admin_written.messages.empty')}</EmptyTitle>
                        </EmptyHeader>
                        <EmptyContent>
                            <Button asChild>
                                <Link href={newMessage}>{t('ops::admin_written.messages.new')}</Link>
                            </Button>
                        </EmptyContent>
                    </Empty>
                ) : (
                    <>
                        <div className="material-base max-w-full overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('ops::admin_written.messages.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('ops::admin_written.messages.columns.to')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.title')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.by')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.status')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.reached')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.sent_by')}</TableHead>
                                        <TableHead>{t('ops::admin_written.messages.columns.sent')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((message) => (
                                        <TableRow key={message.id} className="cursor-pointer" onClick={() => setOpen(message)} data-test={`message-${message.id}`}>
                                            <TableCell className="text-ink" data-test="to">
                                                {audience(message)}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="link"
                                                    className="h-auto max-w-64 justify-start truncate p-0 text-label-14"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        setOpen(message);
                                                    }}
                                                    data-test="open"
                                                >
                                                    {inLanguage(message.titleAr, message.titleEn)}
                                                </Button>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{channelWords(message, t)}</TableCell>
                                            <TableCell>
                                                <WrittenStatus status={message.status} />
                                            </TableCell>
                                            <TableCell>
                                                <Reached row={message} />
                                            </TableCell>
                                            <TableCell className="text-ink-muted">{message.sentBy}</TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">
                                                <Time value={message.createdAt} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: storeCode, after: next }, ['messages', 'next'])} /> : null}
                    </>
                )}
            </div>

            {open === null ? null : <ReadBackDialog open={readBack(open)} onClose={() => setOpen(null)} />}
        </AdminLayout>
    );
}
