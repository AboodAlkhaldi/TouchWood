import { AccountProduct } from '@/components/feedback/account';
import { ShopDate, ShopStars } from '@/components/feedback/shop';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import type { MyReviewData, MyReviewsPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F8 - My Reviews (feedback.md §4.1): every review the customer wrote, in every store, newest first, 20
| then Show More - the product, the store written in, the stars, the size, the text, and its state in
| plain words, with the store's reason when one was written.
*/

const W = 'feedback::shop';

const STATE_TONE: Record<string, Tone> = { PENDING: 'amber-subtle', APPROVED: 'green-subtle', REJECTED: 'red-subtle', UNPUBLISHED: 'gray-subtle' };

export default function MyReviews({ reviews, next }: MyReviewsPage) {
    const t = useTranslator();
    const list = useLoadMore<MyReviewData>(reviews, (row) => row.id);

    return (
        <AccountLayout title={t(`${W}.my.reviews.title`)} subtitle={t(`${W}.my.reviews.subtitle`)} page="feedback.reviews">
            <div className="grid min-w-0 content-start gap-4">
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="my-reviews-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.my.reviews.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className="grid gap-4" data-test="my-reviews">
                        {list.rows.map((review) => (
                            <li key={review.id}>
                                <Card className="gap-3 py-4" data-test={`my-review-${review.id}`}>
                                    <CardContent className="grid gap-3 px-5">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <AccountProduct product={review.product} store={review.storeName} />
                                            <Badge className={tone(STATE_TONE[review.status] ?? 'gray-subtle')} data-test="state">
                                                {t(`${W}.review_status.${review.status}`)}
                                            </Badge>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted">
                                            <ShopStars rating={review.rating} />
                                            <Badge className={tone(review.mark === 'RETURNED' ? 'amber-subtle' : 'green-subtle')}>{t(`${W}.mark.${review.mark}`)}</Badge>
                                            {review.variant.length === 0 ? null : <span>{review.variant.map((value) => `${value.attribute}: ${value.value}`).join(' · ')}</span>}
                                            <ShopDate value={review.writtenAt} />
                                        </div>
                                        <p dir="auto" lang={review.locale} className="text-copy-14 whitespace-pre-line text-ink">
                                            {review.body}
                                        </p>
                                        {review.reason === null ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.reason`, { reason: review.reason })}</p>}
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { after: next }, ['reviews', 'next'])} /> : null}
            </div>
        </AccountLayout>
    );
}
