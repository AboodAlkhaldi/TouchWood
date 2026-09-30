import { useTranslator } from '@/lib/t';
import type { CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, Figure } from './parts';

/*
| The company page's side column (b2b.md §4.5, amendment 16(e)): **the application's lifecycle and
| nothing else** — three steps, with a pointer on the one the latest application has reached. The
| page leaves it out while the company is suspended.
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
                <ol className="grid gap-3">
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
                                        'grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                        index < current
                                            ? 'bg-brand text-ink-on-brand'
                                            : here
                                              ? 'bg-brand text-ink-on-brand ring-4 ring-brand/25'
                                              : 'border border-line-strong text-ink-muted',
                                    ].join(' ')}
                                >
                                    <Figure>{index + 1}</Figure>
                                </span>
                                <span className="grid gap-0.5">
                                    <span className={['text-sm text-ink', here ? 'font-semibold' : 'font-medium'].join(' ')}>
                                        {t(`b2b::company.steps.${step}.title`)}
                                    </span>
                                    <span className="text-xs text-ink-muted">{t(`b2b::company.steps.${step}.body`)}</span>
                                    {here && step === 'decision' ? (
                                        <span
                                            data-test="step-result"
                                            className={['w-fit rounded-md px-2 py-0.5 text-xs font-medium', status === 'APPROVED' ? 'bg-good-soft text-good' : 'bg-bad-soft text-bad'].join(' ')}
                                        >
                                            {t(status === 'APPROVED' ? 'b2b::company.steps.approved' : 'b2b::company.steps.rejected')}
                                        </span>
                                    ) : here ? (
                                        <span className="text-xs font-medium text-brand" data-test="step-now">
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
