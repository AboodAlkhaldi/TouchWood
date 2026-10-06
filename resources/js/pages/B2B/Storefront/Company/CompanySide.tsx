import type { ReactNode } from 'react';
import { Circle, CircleCheck, CircleDot, CircleX } from 'lucide-react';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/cn';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { MARK, Phrase } from './parts';

/*
| The company page's side column (b2b.md §4.5, amendments 16(e) and 26(b)): **the application's
| lifecycle and nothing else** - the card "Your Application", its three steps down the card (the
| owner's pick of two drawings, 2026-10-04): Form, Under Review, Decision, each done, current or still
| to come, with its day once reached and what to do at the current one.
|
| Four states (the owner's): nothing started - no application and no draft, only the button to start
| - every step to come and the card marked Not Started; a draft open, Form current; sent and waiting,
| Form done with the day it was sent, Under Review current; decided, both done and Decision marked
| with its result. A company applying again after a decision is back at Form. Neither Geist nor shadcn
| has a stepper: this is shadcn's Card, a list and Lucide's circles. The page leaves the column out
| while the company is suspended.
*/

type Stage = 'not-started' | 'form' | 'review' | 'decision';
type Mark = 'done' | 'current' | 'upcoming' | 'good' | 'bad';

const REACHED: Record<Stage, number> = { 'not-started': 0, form: 1, review: 2, decision: 3 };

export function CompanySide({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const status = page.company?.status ?? null;
    const decided = status === 'APPROVED' || status === 'REJECTED';
    // The application the card is about: the newest one sent (the list is newest first).
    const latest = page.history[0] ?? null;

    const stage: Stage = page.draft !== null ? 'form' : status === 'PENDING' ? 'review' : decided ? 'decision' : 'not-started';
    const approved = stage === 'decision' && status === 'APPROVED';
    const reached = REACHED[stage];
    const mark = (step: number): Mark => (step < reached ? 'done' : step > reached ? 'upcoming' : step < 3 ? 'current' : approved ? 'good' : 'bad');

    const badge =
        stage === 'not-started' ? (
            <Badge className={tone('gray-subtle')} data-test="lifecycle-badge">
                {t('b2b::company.steps.not_started')}
            </Badge>
        ) : stage === 'decision' ? (
            <Badge className={tone(approved ? 'green-subtle' : 'red-subtle')} data-test="lifecycle-badge">
                {approved ? t('b2b::company.steps.approved') : t('b2b::company.steps.rejected')}
            </Badge>
        ) : null;

    const formLine =
        stage === 'not-started' ? t('b2b::company.steps.form_start') : stage === 'form' ? t('b2b::company.steps.form_now') : <Dated text={t('b2b::company.sent_at', { date: MARK })} at={latest?.submittedAt ?? null} />;
    const reviewLine = stage === 'review' ? t('b2b::company.steps.review_now') : stage === 'decision' ? <Dated text={t('b2b::company.steps.decided', { date: MARK })} at={latest?.decidedAt ?? null} /> : null;
    const decisionLine = stage === 'decision' ? (approved ? t('b2b::company.steps.decision_approved') : t('b2b::company.steps.decision_rejected')) : null;

    return (
        <aside className="grid content-start gap-4">
            <Card data-test="steps" className="material-base gap-0 border-0 py-0">
                <CardHeader className="gap-1 px-5 pt-5 pb-4">
                    <CardTitle className="text-heading-16 text-ink">
                        <h2>{t('b2b::company.steps.title')}</h2>
                    </CardTitle>
                    {badge === null ? null : <CardAction>{badge}</CardAction>}
                </CardHeader>
                <CardContent className="px-5 pb-5">
                    <ol className="grid" data-test="lifecycle" data-step={stage}>
                        <Step mark={mark(1)} name={t('b2b::company.steps.form')} line={formLine} />
                        <Step mark={mark(2)} name={t('b2b::company.steps.review')} line={reviewLine} />
                        <Step mark={mark(3)} name={t('b2b::company.steps.decision')} line={decisionLine} last />
                    </ol>
                </CardContent>
            </Card>
        </aside>
    );
}

const ICON: Record<Mark, { icon: typeof Circle; className: string }> = {
    done: { icon: CircleCheck, className: 'text-brand' },
    current: { icon: CircleDot, className: 'text-brand' },
    upcoming: { icon: Circle, className: 'text-ink-subtle' },
    good: { icon: CircleCheck, className: 'text-good' },
    bad: { icon: CircleX, className: 'text-bad' },
};

/** One step: its circle, a line down to the next, its name, and what it says now. */
function Step({ mark, name, line, last = false }: { mark: Mark; name: string; line: ReactNode; last?: boolean }) {
    const t = useTranslator();
    const { icon: Icon, className } = ICON[mark];

    return (
        <li className="grid grid-cols-[1.25rem_1fr] gap-x-3" data-test={`step-${mark}`} aria-current={mark === 'current' ? 'step' : undefined}>
            <div className="flex flex-col items-center">
                <Icon aria-hidden="true" className={cn('size-5 shrink-0', className)} />
                {last ? null : <span aria-hidden="true" className={cn('my-1 w-px flex-1', mark === 'upcoming' || mark === 'current' ? 'bg-line' : 'bg-brand')} />}
            </div>
            <div className={cn('grid gap-0.5', last ? null : 'pb-4')}>
                <span className={cn('text-label-14 font-medium', mark === 'upcoming' ? 'text-ink-subtle' : 'text-ink')}>
                    {name}
                    {/* The circle said in words, for a screen reader; the current step says so itself. */}
                    {mark === 'current' ? null : <span className="sr-only">{t('b2b::company.separator') + t(`b2b::company.steps.mark.${mark}`)}</span>}
                </span>
                {line === null ? null : <span className="text-copy-13 text-ink-muted">{line}</span>}
            </div>
        </li>
    );
}

/**
 * The day a step was reached, inside its sentence as the main column writes it ("Sent 2h ago",
 * frontend.md 1.10): each language keeps its own order, and the moment no capital mid-sentence.
 */
function Dated({ text, at }: { text: string; at: string | null }) {
    return at === null ? text.replace(MARK, '').trim() : <Phrase text={text} moment={<Time value={at} inSentence />} />;
}
