import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { MoreButton } from '@/components/RowMenu';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { AdminLayout } from '@/layouts/AdminLayout';
import { isolate } from '@/lib/bidi';
import { useKeptDepth } from '@/lib/kept-depth';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { SubscriberData, SubscribersListPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';

/*
| O7 · Subscribers (ops.md §4.15): who gets the newsletter, one store at a time. Newsletters themselves are written and
| sent in Brevo. Only a guest can be removed here: a customer's own switch decides for them (§1.3).
*/

const LIST = '/admin/newsletter/subscribers';

export default function Subscribers({ subscribers, next, stores, storeCode }: SubscribersListPageData) {
    const t = useTranslator();
    const list = useLoadMore<SubscriberData>(subscribers, (subscriber) => subscriber.id);
    const more = (cursor: string) => list.more(LIST, { store: storeCode, after: cursor }, ['subscribers', 'next']);
    // A removal sends back the first page: the rows shown with Show More are asked for again.
    const restoring = useKeptDepth(list, next, more);
    const table = useRef<HTMLDivElement>(null);
    const [removing, setRemoving] = useState<SubscriberData | null>(null);
    const [busy, setBusy] = useState(false);
    const opener = useRef<HTMLElement | null>(null);

    return (
        <AdminLayout title={t('ops::admin_subscribers.title')} subtitle={t('ops::admin_subscribers.subtitle')}>
            <div className="grid gap-4">
                <FormError />
                <StoreFilter stores={stores.map((store) => ({ code: store.code, name: store.name, isActive: store.isOn }))} value={storeCode} className="max-w-xs" />

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="subscribers-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('ops::admin_subscribers.empty')}</EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div ref={table} tabIndex={-1} className="material-base max-w-full overflow-x-auto">
                            <Table>
                                <TableCaption className="sr-only">{t('ops::admin_subscribers.title')}</TableCaption>
                                <TableHeader className="bg-surface-sunken">
                                    <TableRow>
                                        <TableHead>{t('ops::admin_subscribers.columns.email')}</TableHead>
                                        <TableHead>{t('ops::admin_subscribers.columns.language')}</TableHead>
                                        <TableHead>{t('ops::admin_subscribers.columns.kind')}</TableHead>
                                        <TableHead>{t('ops::admin_subscribers.columns.state')}</TableHead>
                                        <TableHead>{t('ops::admin_subscribers.columns.joined')}</TableHead>
                                        <TableHead>{t('ops::admin_subscribers.columns.confirmed')}</TableHead>
                                        <TableHead className="w-10">
                                            <span className="sr-only">{t('ops::admin_subscribers.columns.actions')}</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((subscriber) => (
                                        <TableRow key={subscriber.id} data-test={`subscriber-${subscriber.id}`}>
                                            <TableCell className="text-ink" data-test="email">
                                                {subscriber.email === null ? (
                                                    // A Popover, not a tooltip: a tap opens it on a phone, Enter or Space by keyboard, Escape closes it.
                                                    <Popover>
                                                        <PopoverTrigger asChild>
                                                            <button type="button" className="rounded-sm text-ink-muted underline decoration-dotted" data-test="hidden-email">
                                                                {t('ops::admin_subscribers.hidden')}
                                                            </button>
                                                        </PopoverTrigger>
                                                        <PopoverContent className="w-auto max-w-64 p-2 text-copy-13" align="start" data-test="hidden-why">
                                                            {t('ops::admin_subscribers.hidden_why')}
                                                        </PopoverContent>
                                                    </Popover>
                                                ) : (
                                                    <bdi dir="ltr">{subscriber.email}</bdi>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-ink-muted">{t(`ops::admin_subscribers.language.${subscriber.locale}`)}</TableCell>
                                            <TableCell className="text-ink-muted" data-test="kind">
                                                {t(`ops::admin_subscribers.kind.${subscriber.kind}`)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted" data-test="state">
                                                {subscriber.confirmed ? t('ops::admin_subscribers.state.confirmed') : t('ops::admin_subscribers.state.waiting')}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">
                                                <Time value={subscriber.createdAt} />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{subscriber.confirmedAt === null ? <span className="text-ink-subtle">—</span> : <Time value={subscriber.confirmedAt} />}</TableCell>
                                            <TableCell className="text-end">
                                                {subscriber.kind === 'GUEST' ? (
                                                    <RowActions
                                                        subscriber={subscriber}
                                                        onRemove={(from) => {
                                                            opener.current = from;
                                                            setRemoving(subscriber);
                                                        }}
                                                    />
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>

            {removing === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(open) => (open ? undefined : setRemoving(null))}
                    returnFocusTo={opener}
                    title={t('ops::admin_subscribers.remove.title', { email: isolate(removing.email ?? '') })}
                    description={t('ops::admin_subscribers.remove.body')}
                    busy={busy}
                    cancelLabel={t('ops::admin_subscribers.remove.keep')}
                    confirm={
                        <ActionButton
                            variant="destructive"
                            loading={busy}
                            onClick={() =>
                                router.post(
                                    `${LIST}/${removing.id}/remove`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        // The row's ⋯ button goes with the row: the focus goes back to the table.
                                        onSuccess: () => {
                                            opener.current = table.current;
                                        },
                                        onFinish: () => {
                                            setBusy(false);
                                            setRemoving(null);
                                        },
                                    },
                                )
                            }
                            data-test="confirm-remove"
                        >
                            {t('ops::admin_subscribers.remove.confirm')}
                        </ActionButton>
                    }
                >
                    {null}
                </PanelDialog>
            )}
        </AdminLayout>
    );
}

function RowActions({ subscriber, onRemove }: { subscriber: SubscriberData; onRemove: (from: HTMLElement | null) => void }) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={subscriber.email ?? ''} data-test={`more-${subscriber.id}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuItem variant="destructive" onSelect={() => onRemove(more.current)} data-test="remove">{`${t('ops::admin_subscribers.remove.menu')}…`}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
