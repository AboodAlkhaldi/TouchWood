import { ProgressStops } from '@/components/geist-only/ProgressStops';
import { Badge } from '@/components/ui/badge';
import { intlLocale } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, useLocale } from './parts';

/*
| The company page's side column (b2b.md §4.5, amendments 16(e) and 22(b)): **the application's
| lifecycle and nothing else**, as Geist's Progress with stops - Form, Under Review, Decision - the
| bar at the stop the latest application has reached, and the stage said beside it ("Step 2 of 3 ·
| Under Review"), as Geist asks of a bar with stops. Decided, the result is a Badge: green approved,
| red not. The page leaves the column out while the company is suspended.
*/

const STEPS = ['form', 'review', 'decision'] as const;

export function CompanySide({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const locale = useLocale();
    // Figures in a sentence are the page's own: Arabic-Indic on an Arabic page (frontend.md §1.8).
    const figure = new Intl.NumberFormat(intlLocale(locale));
    const status = page.company?.status ?? null;
    const decided = status === 'APPROVED' || status === 'REJECTED';

    // No application or a draft open: the first stop - a company applying again starts there too.
    // Sent and waiting: the second. Decided: the third, with its result.
    const current = status === 'PENDING' ? 1 : decided && page.draft === null ? 2 : 0;
    const names = [t('b2b::company.steps.form'), t('b2b::company.steps.review'), t('b2b::company.steps.decision')];
    const stage = t('b2b::company.steps.stage', { step: figure.format(current + 1), total: figure.format(STEPS.length), name: names[current] ?? '' });

    return (
        <aside className="grid content-start gap-4">
            <Card title={t('b2b::company.steps.title')} test="steps">
                <div className="grid gap-3" data-test="lifecycle" data-step={STEPS[current]}>
                    <ProgressStops
                        value={current + 1}
                        max={STEPS.length}
                        label={t('b2b::company.steps.title')}
                        valueText={stage}
                        stops={names.map((name, index) => ({ value: index + 1, tooltip: name }))}
                    />
                    <p className="flex flex-wrap items-center gap-2 text-label-13 text-ink-muted" data-test="stage">
                        <span>{stage}</span>
                        {current === 2 ? (
                            <Badge className={tone(status === 'APPROVED' ? 'green-subtle' : 'red-subtle')} data-test="step-result">
                                {status === 'APPROVED' ? t('b2b::company.steps.approved') : t('b2b::company.steps.rejected')}
                            </Badge>
                        ) : null}
                    </p>
                </div>
            </Card>
        </aside>
    );
}
