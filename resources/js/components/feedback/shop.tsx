import { useEffect, useRef, useState } from 'react';
import type { RequestPayload } from '@inertiajs/core';
import { Link, router, usePage } from '@inertiajs/react';
import { Heart, Star } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Messages, TextareaField } from '@/components/Fields';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { PanelDialog } from '@/components/PanelDialog';
import { formatDate, formatMoment, useStoreZone } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { figure, intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { SharedProps } from '@/types/page';
import type { ShopAuthorData, ShopQuestionData, ShopQuestionsData, ShopReviewData, ShopReviewsData, ShopWishlistData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| Feedback's parts of a product's page in the shop (feedback.md §4.1 F5, F6), drawn by Catalog's page in
| its slots: the rating under the title, the Save heart beside the choices, then the reviews - with the
| breakdown by stars, each bar a filter - and the answered questions, each with Show More, and the two
| dialogs that write. A review or a question is shown as written, in its own language (round 3 #4).
|
| Show More asks again for the page's sections and adds this section's next page under what it shows;
| the other sections answer their first page again, which they already show, so they stay as they are.
*/

const W = 'feedback::shop';
const DAY_MS = 86_400_000;

/** The page's sections, as Catalog's product page carries them (catalog.md amendment 26(b)). */
export type FeedbackSections = {
    'feedback.reviews'?: ShopReviewsData | null;
    'feedback.questions'?: ShopQuestionsData | null;
    'feedback.wishlist'?: ShopWishlistData | null;
};

function useLocale(): string {
    return usePage<SharedProps>().props.locale === 'en' ? 'en' : 'ar';
}

function plural(t: ReturnType<typeof useTranslator>, locale: string, key: string, count: number): string {
    return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, { count: figure(locale, count) });
}

/** Five stars filled to the nearest half (owner's Q22 (a)); read as "4.3 out of 5 stars". */
export function ShopStars({ rating, className = 'size-4', label }: { rating: number; className?: string; /** Read aloud instead of Feedback's words, on a page that does not carry them. */ label?: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const filled = Math.round(rating * 2) / 2;

    return (
        <span className="inline-flex items-center gap-0.5" role="img" aria-label={label ?? t(`${W}.rating.stars`, { rating: average(locale, rating) })}>
            {[1, 2, 3, 4, 5].map((n) =>
                n - 0.5 === filled ? (
                    // Half: the empty star, its first half filled over it - the start side, so right in Arabic.
                    <span key={n} className="relative inline-flex" aria-hidden="true" data-test="half-star">
                        <Star className={`${className} text-ink-subtle`} />
                        <span className="absolute inset-y-0 start-0 w-1/2 overflow-hidden">
                            <Star className={`${className} fill-amber-400 text-amber-400 rtl:-scale-x-100`} />
                        </span>
                    </span>
                ) : (
                    <Star key={n} aria-hidden="true" className={`${className} ${n <= filled ? 'fill-amber-400 text-amber-400' : 'text-ink-subtle'}`} />
                ),
            )}
        </span>
    );
}

function average(locale: string, value: number): string {
    return new Intl.NumberFormat(intlLocale(locale), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
}

/** Under the title (§4.1 F5, Q22): the stars, the average, the count - a link down to the reviews; nothing with none. */
export function RatingLine({ reviews }: { reviews: ShopReviewsData | null | undefined }) {
    const t = useTranslator();
    const locale = useLocale();

    if (reviews === null || reviews === undefined || reviews.count === 0 || reviews.average === null) {
        return null;
    }

    return (
        <a href="#reviews" className="inline-flex w-fit items-center gap-2 text-copy-14 text-ink hover:underline" aria-label={`${t(`${W}.rating.to_reviews`)}: ${t(`${W}.rating.stars`, { rating: average(locale, reviews.average) })} ${plural(t, locale, `${W}.rating.count`, reviews.count)}`} data-test="rating-line">
            <ShopStars rating={reviews.average} />
            <span className="tw-figure font-medium">{average(locale, reviews.average)}</span>
            <span className="tw-figure text-ink-muted">{plural(t, locale, `${W}.rating.count`, reviews.count)}</span>
        </a>
    );
}

/**
 * A review's or an answer's date (owner's Q23): "3 days ago" up to 6 days, "a week ago" at 7, the date
 * from 8 on; the full date and time on hover.
 */
export function ShopDate({ value }: { value: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const zone = useStoreZone();
    const then = new Date(value).getTime();
    const ago = Date.now() - then;
    const days = Math.floor(ago / DAY_MS);
    const words = new Intl.RelativeTimeFormat(intlLocale(locale), { numeric: 'auto' });
    const text = Number.isNaN(then)
        ? value
        : days < 1
          ? ago < 3_600_000
              ? words.format(-Math.max(1, Math.round(ago / 60_000)), 'minute')
              : words.format(-Math.round(ago / 3_600_000), 'hour')
          : days <= 6
            ? words.format(-days, 'day')
            : days === 7
              ? t(`${W}.date.week`)
              : formatDate(value, zone, locale);

    return (
        <time dateTime={value} title={formatMoment(value, zone, locale)} suppressHydrationWarning className="tw-figure">
            {text}
        </time>
    );
}

/** The reviews narrowed to one star, as the address says it (§4.2 B1); every section's Show More keeps it. */
function starFilter(star: number | null): Record<string, Record<string, string>> {
    return star === null ? {} : { filter: { 'feedback.reviews': String(star) } };
}

function authorName(t: ReturnType<typeof useTranslator>, author: ShopAuthorData): string {
    return author.deleted ? t(`${W}.deleted_customer`) : t(`${W}.author`, { first: author.firstName, initial: author.lastInitial });
}

/**
 * One section's rows as shown: its first page, and each next page added under it. A new first page - a
 * star chosen, a review written - starts it again; the same first page again (another section's Show
 * More) leaves it as it is.
 */
function useSectionRows<T>(rows: T[], next: string | null, key: (row: T) => string, scope = '') {
    const [shown, setShown] = useState({ rows, next });
    const first = useRef(`${scope}|${rows.map(key).join(' ')}`);
    const appending = useRef(false);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const ids = `${scope}|${rows.map(key).join(' ')}`;

        if (appending.current) {
            appending.current = false;
            setShown((before) => {
                const seen = new Set(before.rows.map(key));

                return { rows: [...before.rows, ...rows.filter((row) => !seen.has(key(row)))], next };
            });

            return;
        }

        if (ids !== first.current) {
            first.current = ids;
            setShown({ rows, next });
        }
        // Only a new answer from the server should run this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, next, scope]);

    function more(url: string, query: RequestPayload) {
        if (loading) {
            return;
        }

        appending.current = true;
        router.get(url, query, {
            only: ['sections'],
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            onStart: () => setLoading(true),
            onFinish: () => {
                setLoading(false);
                appending.current = false;
            },
        });
    }

    return { rows: shown.rows, next: shown.next, loading, more };
}

/** Beside the choices (§4.1 F5, Q17, Q18): Save, filled once saved; a guest is sent to sign in and comes back with it saved. */
export function SaveHeart({ wishlist, productId, name }: { wishlist: ShopWishlistData | null | undefined; productId: string; name: string }) {
    const t = useTranslator();
    const link = useLink();
    const [busy, setBusy] = useState(false);
    const saved = wishlist?.saved === true;

    function press() {
        const route = saved ? 'storefront.feedback.remove' : 'storefront.feedback.save';

        router.post(link(route, { product: productId }), { back: window.location.pathname + window.location.search }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    }

    return (
        <ActionButton type="button" variant="outline" className="w-fit" loading={busy} aria-pressed={saved} aria-label={saved ? t(`${W}.save.remove`, { name }) : t(`${W}.save.add`, { name })} onClick={press} data-test="save-heart">
            <Heart aria-hidden="true" className={saved ? 'fill-red-500 text-red-500' : undefined} />
            {saved ? t(`${W}.save.saved`) : t(`${W}.save.save`)}
        </ActionButton>
    );
}

/** The reviews (§4.1 F5): the average and count, the breakdown by stars - each bar a filter - each approved review, newest first. */
export function ReviewsSection({ reviews, url, productId }: { reviews: ShopReviewsData; url: string; productId: string }) {
    const t = useTranslator();
    const locale = useLocale();
    const list = useSectionRows<ShopReviewData>(reviews.reviews, reviews.next, (row) => row.reviewId, String(reviews.star ?? ''));
    const [writing, setWriting] = useState(false);
    const [wrote, setWrote] = useState(false);
    const reviewed = wrote || reviews.reviewed;
    const choose = (star: number | null) => router.get(url, starFilter(star), { only: ['sections'], preserveState: true, preserveScroll: true });
    const total = reviews.stars.reduce((sum, each) => sum + each.count, 0);

    return (
        <section id="reviews" className="grid scroll-mt-24 gap-4" data-test="reviews">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-heading-20 text-ink">{t(`${W}.reviews.title`)}</h2>
                {reviews.canWrite && !reviewed ? (
                    <Button type="button" variant="outline" onClick={() => setWriting(true)} data-test="write-review">
                        {t(`${W}.reviews.write`)}
                    </Button>
                ) : null}
            </div>
            {reviewed ? (
                <p className="text-copy-14 text-ink-muted" data-test="review-written">
                    {t(`${W}.reviews.written`)}
                </p>
            ) : null}

            {total === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t(`${W}.reviews.none`)}</p>
            ) : (
                <div className="grid gap-6 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]">
                    <div className="grid content-start gap-3">
                        {reviews.average === null ? null : (
                            <p className="flex items-center gap-2">
                                <span className="tw-figure text-heading-32 text-ink">{average(locale, reviews.average)}</span>
                                <span className="grid gap-1">
                                    <ShopStars rating={reviews.average} />
                                    <span className="tw-figure text-copy-13 text-ink-muted">{plural(t, locale, `${W}.rating.count`, reviews.count)}</span>
                                </span>
                            </p>
                        )}
                        <ul className="grid gap-1" aria-label={t(`${W}.reviews.breakdown`)} data-test="breakdown">
                            {reviews.stars.map((each) => {
                                const chosen = reviews.star === each.star;

                                return (
                                    <li key={each.star}>
                                        <button
                                            type="button"
                                            aria-pressed={chosen}
                                            aria-label={`${t(`${W}.reviews.only`, { star: figure(locale, each.star) })} · ${t(`${W}.reviews.bar`, { star: figure(locale, each.star), count: figure(locale, each.count) })}`}
                                            onClick={() => choose(chosen ? null : each.star)}
                                            className={`grid w-full grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-center gap-2 rounded-md px-1 py-1 text-copy-13 text-ink hover:bg-surface-sunken ${chosen ? 'shadow-[0_0_0_2px_var(--tw-ink)]' : ''}`}
                                            data-test={`star-${each.star}`}
                                        >
                                            <span className="tw-figure inline-flex items-center gap-0.5">
                                                {figure(locale, each.star)}
                                                <Star aria-hidden="true" className="size-3 fill-amber-400 text-amber-400" />
                                            </span>
                                            <span className="h-2 overflow-hidden rounded-full bg-surface-sunken" aria-hidden="true">
                                                <span className="block h-full rounded-full bg-amber-400" style={{ width: `${total === 0 ? 0 : Math.round((each.count / total) * 100)}%` }} />
                                            </span>
                                            <span className="tw-figure text-end text-ink-muted">{figure(locale, each.count)}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        {reviews.star === null ? null : (
                            <p className="grid gap-1 text-copy-13 text-ink-muted" data-test="star-filter">
                                {t(`${W}.reviews.showing`, { star: figure(locale, reviews.star) })}
                                <Button type="button" variant="link" size="sm" className="h-auto w-fit p-0" onClick={() => choose(null)} data-test="clear-star">
                                    {t(`${W}.reviews.clear`)}
                                </Button>
                            </p>
                        )}
                    </div>

                    <div className="grid content-start gap-4">
                        {list.rows.length === 0 ? (
                            <p className="text-copy-14 text-ink-muted">{t(`${W}.reviews.none_with`, { star: figure(locale, reviews.star ?? 0) })}</p>
                        ) : (
                            <ul className="grid gap-4" data-test="review-list">
                                {list.rows.map((review) => (
                                    <li key={review.reviewId} className="grid gap-2 border-b border-line pb-4 last:border-b-0" data-test="review">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <ShopStars rating={review.rating} />
                                            <span className="text-label-14 text-ink">{authorName(t, review.author)}</span>
                                            <Badge className={tone(review.mark === 'RETURNED' ? 'amber-subtle' : 'green-subtle')}>{t(`${W}.mark.${review.mark}`)}</Badge>
                                        </div>
                                        <p className="flex flex-wrap gap-x-2 text-copy-13 text-ink-muted">
                                            {review.variant.length === 0 ? null : <span>{review.variant.map((value) => `${value.attribute}: ${value.value}`).join(' · ')}</span>}
                                            <ShopDate value={review.writtenAt} />
                                        </p>
                                        <p dir="auto" lang={review.locale} className="text-copy-14 whitespace-pre-line text-ink">
                                            {review.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {list.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(url, { ...starFilter(reviews.star), after: { 'feedback.reviews': list.next } })} /> : null}
                    </div>
                </div>
            )}

            {writing ? (
                <WriteReviewDialog
                    productId={productId}
                    onClose={() => setWriting(false)}
                    onDone={() => {
                        setWriting(false);
                        setWrote(true);
                    }}
                />
            ) : null}
        </section>
    );
}

/** The answered questions (§4.1 F5): the asker, the question, the answer signed by the store's team, newest answer first. */
export function QuestionsSection({ questions, url, productId, star }: { questions: ShopQuestionsData; url: string; productId: string; /** The reviews' star filter, kept by Show More. */ star: number | null }) {
    const t = useTranslator();
    const link = useLink();
    const { shopper } = usePage<SharedProps>().props;
    const list = useSectionRows<ShopQuestionData>(questions.questions, questions.next, (row) => row.questionId);
    const [asking, setAsking] = useState(false);

    return (
        <section id="questions" className="grid scroll-mt-24 gap-4" data-test="questions">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-heading-20 text-ink">{t(`${W}.questions.title`)}</h2>
                {questions.canAsk ? (
                    <Button type="button" variant="outline" onClick={() => setAsking(true)} data-test="ask-question">
                        {t(`${W}.questions.ask`)}
                    </Button>
                ) : shopper === null || shopper === undefined ? (
                    <Link href={link('storefront.feedback.sign-in-here', { back: url })} className="text-label-14 text-ink underline underline-offset-4" data-test="sign-in-to-ask">
                        {t(`${W}.questions.sign_in`)}
                    </Link>
                ) : null}
            </div>

            {list.rows.length === 0 ? (
                <p className="text-copy-14 text-ink-muted">{t(`${W}.questions.none`)}</p>
            ) : (
                <ul className="grid gap-4" data-test="question-list">
                    {list.rows.map((question) => (
                        <li key={question.questionId} className="grid gap-2 border-b border-line pb-4 last:border-b-0" data-test="question">
                            <p className="text-copy-13 text-ink-muted">{authorName(t, question.author)}</p>
                            <p dir="auto" lang={question.locale} className="text-label-14 whitespace-pre-line text-ink">
                                {question.body}
                            </p>
                            <div className="material-small grid gap-1 bg-surface-sunken px-3 py-2">
                                <p dir="auto" lang={question.locale} className="text-copy-14 whitespace-pre-line text-ink">
                                    {question.answer}
                                </p>
                                <p className="flex flex-wrap gap-x-2 text-copy-13 text-ink-muted">
                                    <span>{t(`${W}.questions.answer_by`, { store: question.store })}</span>
                                    <ShopDate value={question.answeredAt} />
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {list.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(url, { ...starFilter(star), after: { 'feedback.questions': list.next } })} /> : null}

            {asking ? <AskQuestionDialog productId={productId} onClose={() => setAsking(false)} /> : null}
        </section>
    );
}

/** Write a Review (§4.1 F6): five stars to pick, the text (1–2,000); shown once the store approves it. */
function WriteReviewDialog({ productId, onClose, onDone }: { productId: string; onClose: () => void; onDone: () => void }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const [rating, setRating] = useState(0);
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const checks = useChecks([
        { id: 'review-rating', focus: 'rate-1', label: t(`${W}.write.stars`), value: rating === 0 ? '' : String(rating), rules: { required: true } },
        { id: 'review-body', label: t(`${W}.write.body`), value: body, rules: { required: true, length: { max: 2000 } } },
    ]);

    const stars = checks.box('review-rating');

    function pick(n: number) {
        setRating(n);
        stars.onType();
        document.getElementById(`rate-${n}`)?.focus();
    }

    function submit() {
        checks.submit(() =>
            router.post(link('storefront.feedback.review', { product: productId }), { rating, body }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onDone }),
        );
    }

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t(`${W}.write.title`)}
            description={t(`${W}.write.description`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={checks.reason} onClick={submit} data-test="submit-review">
                    {t(`${W}.write.submit`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <fieldset className="grid gap-2">
                    <legend id="review-rating" className="mb-2 text-label-14 text-ink">
                        {t(`${W}.write.stars`)}
                    </legend>
                    <div
                        className="flex gap-1"
                        role="radiogroup"
                        aria-labelledby="review-rating"
                        aria-describedby={stars.message ? 'review-rating-error' : undefined}
                        aria-invalid={stars.message ? true : undefined}
                        onKeyDown={(event) => {
                            // The radio pattern: one stop, the arrows move - forward is left in Arabic.
                            const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
                            const step = { ArrowRight: rtl ? -1 : 1, ArrowDown: 1, ArrowLeft: rtl ? 1 : -1, ArrowUp: -1 }[event.key];

                            if (step !== undefined) {
                                event.preventDefault();
                                pick(Math.min(5, Math.max(1, (rating || (step > 0 ? 0 : 6)) + step)));
                            }
                        }}
                    >
                        {[1, 2, 3, 4, 5].map((n) => (
                            <button
                                key={n}
                                id={`rate-${n}`}
                                type="button"
                                role="radio"
                                aria-checked={rating === n}
                                tabIndex={rating === n || (rating === 0 && n === 1) ? 0 : -1}
                                aria-label={plural(t, locale, `${W}.write.star`, n)}
                                onClick={() => pick(n)}
                                className="rounded-md p-1 hover:bg-surface-sunken"
                                data-test={`rate-${n}`}
                            >
                                <Star aria-hidden="true" className={`size-7 ${n <= rating ? 'fill-amber-400 text-amber-400' : 'text-ink-subtle'}`} />
                            </button>
                        ))}
                    </div>
                    <Messages id="review-rating" error={stars.message} check={stars} />
                </fieldset>
                <TextareaField id="review-body" dir="auto" rows={5} label={t(`${W}.write.body`)} value={body} check={checks.box('review-body')} onChange={(event) => setBody(event.target.value)} data-test="review-body" />
            </div>
        </PanelDialog>
    );
}

/** Ask a Question (§4.1 F6): the question (1–1,000); the answer shows on the page and in the account. */
function AskQuestionDialog({ productId, onClose }: { productId: string; onClose: () => void }) {
    const t = useTranslator();
    const link = useLink();
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const checks = useChecks([{ id: 'question-body', label: t(`${W}.ask.body`), value: body, rules: { required: true, length: { max: 1000 } } }]);

    function submit() {
        checks.submit(() => router.post(link('storefront.feedback.ask', { product: productId }), { body }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose }));
    }

    return (
        <PanelDialog
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={t(`${W}.ask.title`)}
            description={t(`${W}.ask.description`)}
            busy={busy}
            confirm={
                <ActionButton loading={busy} disabledReason={checks.reason} onClick={submit} data-test="submit-question">
                    {t(`${W}.ask.submit`)}
                </ActionButton>
            }
        >
            <TextareaField id="question-body" dir="auto" rows={4} label={t(`${W}.ask.body`)} value={body} check={checks.box('question-body')} onChange={(event) => setBody(event.target.value)} data-test="question-body" />
        </PanelDialog>
    );
}
