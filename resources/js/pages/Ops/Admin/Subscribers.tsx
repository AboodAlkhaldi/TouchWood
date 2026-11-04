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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { AdminLayout } from '@/layouts/AdminLayout';
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
    useKeptDepth(list, next, more);
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
                        <div className="material-base max-w-full overflow-x-auto">
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
                                        <TableHead className="w-10" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {list.rows.map((subscriber) => (
                                        <TableRow key={subscriber.id} data-test={`subscriber-${subscriber.id}`}>
                                            <TableCell className="text-ink" data-test="email">
                                                {subscriber.email === null ? (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span tabIndex={0} className="text-ink-muted underline decoration-dotted" data-test="hidden-email">
                                                                {t('ops::admin_subscribers.hidden')}
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>{t('ops::admin_subscribers.hidden_why')}</TooltipContent>
                                                    </Tooltip>
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
                                                <Time value={subscriber.createdAt} mode="absolute" />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap text-ink-muted">{subscriber.confirmedAt === null ? <span className="text-ink-subtle">—</span> : <Time value={subscriber.confirmedAt} mode="absolute" />}</TableCell>
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
                        {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => more(next)} /> : null}
                    </>
                )}
            </div>

            {removing === null ? null : (
                <PanelDialog
                    destructive
                    open
                    onOpenChange={(open) => (open ? undefined : setRemoving(null))}
                    returnFocusTo={opener}
                    title={t('ops::admin_subscribers.remove.title', { email: removing.email ?? '' })}
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
                                        // Without it the page starts over, and the rows shown with Show More can't be asked for again.
                                        preserveState: true,
                                        onStart: () => setBusy(true),
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
