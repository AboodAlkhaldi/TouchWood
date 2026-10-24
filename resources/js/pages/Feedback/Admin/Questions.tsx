import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreFilter } from '@/components/StoreFilter';
import { Time } from '@/components/Time';
import { BulkBar, SelectBox, useSelection } from '@/components/feedback/bulk';
import { Author, DecisionDialog, ProductCell } from '@/components/feedback/parts';
import { useLocale } from '@/components/promotions/parts';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader } from '@/components/ui/empty';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { QuestionItemData, ReasonData, SupportListPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| F2 - Support › Product Questions (feedback.md §4.1): the store's questions by status - Waiting first,
| oldest on top - 50 at a time. An answerer answers a waiting question (signed "{store}'s team" wherever
| it is read, §1.3) or rejects it with an optional reason; an answered one may be corrected or
| unpublished. Who answered and when is the staff's alone (owner's Q7).
*/

const W = 'feedback::admin_support';
const LIST = '/admin/questions';
const TABS = ['PENDING', 'ANSWERED', 'REJECTED', 'UNPUBLISHED'] as const;

type Dialog = 'answer' | 'correct' | 'reject' | 'unpublish';

export default function Questions(page: SupportListPage) {
    const t = useTranslator();
    const list = useLoadMore(page.questions, (row) => row.id);
    const status = page.status ?? 'PENDING';
    const locale = useLocale();
    const selection = useSelection();
    // Many at once (owner's Q4): reject the waiting, unpublish the answered.
    const bulk = !page.mayAct ? [] : status === 'PENDING' ? (['reject'] as const) : status === 'ANSWERED' ? (['unpublish'] as const) : [];

    return (
        <AdminLayout title={t(`${W}.questions.title`)} subtitle={t(`${W}.questions.subtitle`)}>
            <div className="grid gap-4">
                <FormError />
                <div className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)] sm:items-end">
                    <Tabs value={status} onValueChange={(next) => router.get(LIST, { store: page.storeCode, status: next }, { preserveState: true })}>
                        <TabsList className="max-w-full min-w-0 justify-start overflow-x-auto" aria-label={t(`${W}.questions.tabs_label`)} data-test="status-tabs">
                            {TABS.map((each) => (
                                <TabsTrigger key={each} value={each} data-test={`status-${each}`}>
                                    {t(`${W}.question_status.${each}`)}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                    <StoreFilter stores={page.stores} value={page.storeCode} />
                </div>

                {bulk.length > 0 && list.rows.length > 0 ? (
                    <BulkBar all={list.rows.map((row) => row.id)} selection={selection} locale={locale} url="/admin/questions/bulk" store={page.storeCode} actions={[...bulk]} reasons={page.reasons} />
                ) : null}

                {list.rows.length === 0 ? (
                    <Empty className="material-base" data-test="questions-empty">
                        <EmptyHeader>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t(`${W}.questions.empty`)}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <ul className="grid gap-3" data-test="questions">
                        {list.rows.map((question) => (
                            <li key={question.id}>
                                <QuestionCard question={question} mayAct={page.mayAct} storeName={page.storeName} reasons={page.reasons} selectable={bulk.length > 0} selected={selection.has(question.id)} onSelect={() => selection.toggle(question.id)} />
                            </li>
                        ))}
                    </ul>
                )}

                {page.next !== null ? <LoadMoreButton loading={list.loading} onClick={() => list.more(LIST, { store: page.storeCode, status, after: page.next ?? '' }, ['questions', 'next'])} /> : null}
            </div>
        </AdminLayout>
    );
}

function QuestionCard({
    question,
    mayAct,
    storeName,
    reasons,
    selectable,
    selected,
    onSelect,
}: {
    question: QuestionItemData;
    mayAct: boolean;
    storeName: string;
    reasons: ReasonData[];
    selectable: boolean;
    selected: boolean;
    onSelect: () => void;
}) {
    const t = useTranslator();
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const answering = dialog === 'answer' || dialog === 'correct';

    return (
        <Card className="material-base border-0 py-4" data-test={`question-${question.id}`}>
            <CardContent className="grid gap-3 px-5">
                <div className="flex items-start gap-3">
                    {selectable ? <SelectBox checked={selected} onChange={onSelect} name={question.product.name} test={`select-${question.id}`} /> : null}
                    <ProductCell product={question.product} />
                </div>
                <p dir="auto" className="text-label-14 whitespace-pre-line text-ink">
                    {question.body}
                </p>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-copy-13 text-ink-muted">
                    <span className="text-label-13 text-ink">
                        <Author author={question.author} />
                    </span>
                    <Time value={question.askedAt} />
                </div>
                {question.answer === null ? null : (
                    <div className="grid gap-1 rounded-[var(--tw-radius)] bg-surface-sunken p-3">
                        <p dir="auto" className="text-copy-14 whitespace-pre-line text-ink">
                            {question.answer}
                        </p>
                        <p className="flex flex-wrap gap-x-3 text-copy-13 text-ink-muted">
                            {question.answeredBy === null ? null : <span>{t(`${W}.item.answered_by`, { name: question.answeredBy })}</span>}
                            {question.answeredAt === null ? null : <Time value={question.answeredAt} />}
                        </p>
                    </div>
                )}
                {question.reason === null ? null : <p className="text-copy-13 text-ink-muted">{t(`${W}.item.reason`, { reason: question.reason })}</p>}

                {mayAct && (question.status === 'PENDING' || question.status === 'ANSWERED') ? (
                    <div className="flex flex-wrap justify-end gap-2">
                        {question.status === 'PENDING' ? (
                            <>
                                <Button type="button" variant="outline" onClick={() => setDialog('reject')} data-test={`reject-${question.id}`}>
                                    {`${t(`${W}.actions.reject`)}…`}
                                </Button>
                                <Button type="button" onClick={() => setDialog('answer')} data-test={`answer-${question.id}`}>
                                    {`${t(`${W}.actions.answer`)}…`}
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button type="button" variant="outline" onClick={() => setDialog('unpublish')} data-test={`unpublish-${question.id}`}>
                                    {`${t(`${W}.actions.unpublish`)}…`}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setDialog('correct')} data-test={`correct-${question.id}`}>
                                    {`${t(`${W}.actions.correct`)}…`}
                                </Button>
                            </>
                        )}
                    </div>
                ) : null}
            </CardContent>
            {dialog === null ? null : (
                <DecisionDialog
                    open
                    onOpenChange={(open) => (open ? undefined : setDialog(null))}
                    title={answering ? t(`${W}.dialog.${dialog === 'answer' ? 'answer_title' : 'correct_title'}`) : t(`${W}.dialog.${dialog}_question`)}
                    description={answering ? t(`${W}.dialog.answer_hint`, { store: storeName }) : storeName}
                    action={`/admin/questions/${question.id}/${dialog}`}
                    field={answering ? 'answer' : 'reason'}
                    initial={dialog === 'correct' ? (question.answer ?? '') : ''}
                    confirm={answering ? t(`${W}.dialog.save_answer`) : t(`${W}.dialog.${dialog}_question`)}
                    destructive={!answering}
                    reasons={reasons}
                    language={question.locale}
                />
            )}
        </Card>
    );
}
