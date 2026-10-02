import { Badge } from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type { CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, Figure } from './parts';

/*
| The company page's side column (b2b.md §4.5, amendment 16(e)): **the application's lifecycle and
| nothing else** — three steps, with a pointer on the one the latest application has reached. The
| page leaves it out while the company is suspended.
|
| Geist has no stepper, so it is built from Geist's parts (frontend.md §1.8, 1.10): a card of base
| material, Geist's type, and the decision's result as a Badge — green when approved, red when not.
*/

const STEPS = ['send', 'review', 'decision'] as const;

export function CompanySide({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const status = page.company?.status ?? null;
    const decided = status === 'APPROVED' || status === 'REJECTED';

    // No application or a draft open: the first step — a company applying again starts there too.
    // Sent and waiting: the second. Decided: the third, with its result.
    const current = status === 'PENDING' ? 1 : decided && page.draft === null ? 2 : 0;

    return (
        <aside className="grid content-start gap-4">
            <Card title={t('b2b::company.steps.title')} test="steps">
                <ol className="grid gap-4">
                    {STEPS.map((step, index) => {
                        const here = index === current;

                        return (
                            <li
                                key={step}
                                className="flex gap-3"
                                data-test={`step-${step}`}
                                data-state={index < current ? 'done' : here ? 'current' : 'ahead'}
                                aria-current={here ? 'step' : undefined}
                            >
                                <span
                                    aria-hidden
                                    className={[
                                        'grid size-6 shrink-0 place-items-center rounded-full text-label-12 font-semibold',
                                        index < current
                                            ? 'bg-brand text-ink-on-brand'
                                            : here
                                              ? 'bg-brand text-ink-on-brand ring-4 ring-brand/25'
                                              : 'border border-line-strong text-ink-muted',
                                    ].join(' ')}
                                >
                                    <Figure>{index + 1}</Figure>
                                </span>
                                <span className="grid justify-items-start gap-1">
                                    <span className={['text-label-14 text-ink', here ? 'font-semibold' : 'font-medium'].join(' ')}>
                                        {t(`b2b::company.steps.${step}.title`)}
                                        {/* The filled circle says it to the eye; this says it aloud (17, L8). */}
                                        {index < current ? <span className="sr-only"> — {t('b2b::company.steps.done')}</span> : null}
                                    </span>
                                    <span className="text-copy-13 text-ink-muted">{t(`b2b::company.steps.${step}.body`)}</span>
                                    {here && step === 'decision' ? (
                                        <Badge variant={status === 'APPROVED' ? 'green-subtle' : 'red-subtle'} data-test="step-result">
                                            {t(status === 'APPROVED' ? 'b2b::company.steps.approved' : 'b2b::company.steps.rejected')}
                                        </Badge>
                                    ) : here ? (
                                        <span className="text-label-13 font-medium text-brand" data-test="step-now">
                                            {t('b2b::company.steps.now')}
                                        </span>
                                    ) : null}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            </Card>
        </aside>
    );
}
