import { AccountProduct } from '@/components/feedback/account';
import { ShopDate } from '@/components/feedback/shop';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import { useLoadMore } from '@/lib/use-load-more';
import type { MyQuestionData, MyQuestionsPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F8 - My Questions (feedback.md §4.1): every question the customer asked, in every store, newest first -
| its store, its state in plain words, the answer once given, the store's reason when one was written.
*/

const W = 'feedback::shop';

const STATE_TONE: Record<string, Tone> = { PENDING: 'amber-subtle', ANSWERED: 'green-subtle', REJECTED: 'red-subtle', UNPUBLISHED: 'gray-subtle' };

export default function MyQuestions({ questions, next }: MyQuestionsPage) {
    const t = useTranslator();
    const list = useLoadMore<MyQuestionData>(questions, (row) => row.id);

    return (
        <AccountLayout title={t(`${W}.my.questions.title`)} subtitle={t(`${W}.my.questions.subtitle`)} page="feedback.questions">
            <div className="grid min-w-0 content-start gap-4">
                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="my-questions-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.my.questions.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className="grid gap-4" data-test="my-questions">
                        {list.rows.map((question) => (
                            <li key={question.id}>
                                <Card className="gap-3 py-4" data-test={`my-question-${question.id}`}>
                                    <CardContent className="grid gap-3 px-5">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <AccountProduct product={question.product} store={question.storeName} />
                                            <Badge className={tone(STATE_TONE[question.status] ?? 'gray-subtle')} data-test="state">
                                                {t(`${W}.question_status.${question.status}`)}
                                            </Badge>
                                        </div>
                                        <p dir="auto" lang={question.locale} className="text-label-14 whitespace-pre-line text-ink">
                                            {question.body}
                                        </p>
                                        <p className="text-copy-13 text-ink-muted">
                                            <ShopDate value={question.askedAt} />
                                        </p>
                                        {question.answer === null ? null : (
                                            <div className="material-small grid gap-1 bg-surface-sunken px-3 py-2" data-test="answer">
                                                <p dir="auto" lang={question.locale} className="text-copy-14 whitespace-pre-line text-ink">
                                                    {question.answer}
                                                </p>
                                                <p className="flex flex-wrap gap-x-2 text-copy-13 text-ink-muted">
                                                    <span>{t(`${W}.questions.answer_by`, { store: question.storeName })}</span>
                                                    {question.answeredAt === null ? null : <ShopDate value={question.answeredAt} />}
                                                </p>
                                            </div>
                                        )}
                                        {question.reason === null ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.reason`, { reason: question.reason })}</p>}
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
                {next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(window.location.pathname, { after: next }, ['questions', 'next'])} /> : null}
            </div>
        </AccountLayout>
    );
}
