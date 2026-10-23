import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { Author, DecisionDialog, ProductCell, Stars } from '@/components/feedback/parts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import type { ReviewItemData, SupportListPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F1 - Support › Reviews (feedback.md §4.1): the store's reviews by status - Waiting first, oldest on top
| - 50 at a time with Show More. A moderator approves a waiting review at once, or rejects it with an
| optional reason; a published one may be unpublished the same way (§1.1). The author is their public
| name only.
*/

const W = 'feedback::admin_support';
const LIST = '/admin/reviews';
const TABS = ['PENDING', 'APPROVED', 'REJECTED', 'UNPUBLISHED'] as const;

export default function Reviews(page: SupportListPage) {
    const t = useTranslator();
    const list = useLoadMore(page.reviews, (row) => row.id);
    const status = page.status ?? 'PENDING';

    return (
        <AdminLayout title={t(`${W}.reviews.title`)} subtitle={t(`${W}.reviews.subtitle`)}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-end">
                    <Tabs value={status} onValueChange={(next) => router.get(LIST, { store: page.storeCode, status: next }, { preserveState: true })}>
                        <TabsList aria-label={t(`${W}.reviews.tabs_label`)} data-test="status-tabs">
                            {TABS.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`status-${each}`}>
                                    {t(`${W}.review_status.${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                    <StoreFilter stores={page.stores} value={page.storeCode} />
                </div>

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="reviews-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.reviews.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className="grid gap-3" data-test="reviews">
                        {list.rows.map((review) => (
                            <li key={review.id}>
                                <ReviewCard review={review} mayAct={page.mayAct} storeName={page.storeName} />
                            </li>
                        ))}
                    </ul>
                )}

                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: page.storeCode, status, after: page.next ?? '' }, ['reviews', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}

function ReviewCard({ review, mayAct, storeName }: { review: ReviewItemData; mayAct: boolean; storeName: string }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<'reject' | 'unpublish' | null>(null);
    const size = review.variant.map((value) => `${value.attribute}: ${value.value}`).join(' · ');

    return (
        <Card className="material-base border-0 py-4" data-test={`review-${review.id}`}>
            <CardContent className="grid gap-3 px-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ProductCell product={review.product} />
                    <div className="flex flex-wrap items-center gap-2">
                        <Stars rating={review.rating} />
                        <Badge className={tone(review.mark === 'RETURNED' ? 'amber-subtle' : 'green-subtle')}>{t(`${W}.mark.${review.mark}`)}</Badge>
                    </div>
                </div>
                {size === '' ? null : <p className="text-copy-13 text-ink-muted">{size}</p>}
                <p dir="auto" className="text-copy-14 whitespace-pre-line text-ink">
                    {review.body}
                </p>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-copy-13 text-ink-muted">
                    <span className="text-label-13 text-ink">
                        <Author author={review.author} />
                    </span>
                    <Time value={review.writtenAt} />
                    {review.decidedBy === null ? null : <span>{t(`${W}.item.decided`, { name: review.decidedBy })}</span>}
                    {review.decidedAt === null ? null : <Time value={review.decidedAt} />}
                </div>
                {review.reason === null ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.item.reason`, { reason: review.reason })}</p>}

                {mayAct && (review.status === 'PENDING' || review.status === 'APPROVED') ? (
                    <div className="flex flex-wrap justify-end gap-2">
                        {review.status === 'PENDING' ? (
                            <>
                                <Button type="button" variant="outline" onClick={() => setDialog('reject')} data-test={`reject-${review.id}`}>
                                    {`${t(`${W}.actions.reject`)}…`}
                                </Button>
                                <ActionButton
                                    type="button"
                                    loading={busy}
                                    data-test={`approve-${review.id}`}
                                    onClick={() => router.post(`/admin/reviews/${review.id}/approve`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                                >
                                    {t(`${W}.actions.approve`)}
                                </ActionButton>
                            </>
                        ) : (
                            <Button type="button" variant="outline" onClick={() => setDialog('unpublish')} data-test={`unpublish-${review.id}`}>
                                {`${t(`${W}.actions.unpublish`)}…`}
                            </Button>
                        )}
                    </div>
                ) : null}
            </CardContent>
            {dialog === null ? null : (
                <DecisionDialog
                    open
                    onOpenChange={(open) => (open ? undefined : setDialog(null))}
                    title={t(`${W}.dialog.${dialog}_review`)}
                    description={storeName}
                    action={`/admin/reviews/${review.id}/${dialog}`}
                    field="reason"
                    confirm={t(`${W}.dialog.${dialog}_review`)}
                    destructive
                />
            )}
        </Card>
    );
}
