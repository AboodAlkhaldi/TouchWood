import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';
import type { CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, Figure } from './parts';

/*
| The company page's side column (b2b.md §4.5, the design's): what happens after sending, how a
| company pays, and what it may do before it is approved.
*/

const STEPS = ['send', 'review', 'decision', 'prices'] as const;

export function CompanySide({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const status = page.company?.status ?? null;
    const approved = status === 'APPROVED';

    // How far along the steps the company is: none sent, waiting, decided, or ordering.
    const reached = status === null ? 0 : status === 'PENDING' ? 1 : status === 'REJECTED' ? 2 : approved ? 4 : 0;

    return (
        <aside className="grid content-start gap-4">
            <Card title={t('b2b::company.steps.title')} test="steps">
                <ol className="grid gap-3">
                    {STEPS.map((step, index) => (
                        <li key={step} className="flex gap-3" data-test={`step-${step}`} data-reached={index < reached ? 'yes' : 'no'}>
                            <span
                                aria-hidden
                                className={[
                                    'grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                    index < reached ? 'bg-brand text-ink-on-brand' : 'border border-line-strong text-ink-muted',
                                ].join(' ')}
                            >
                                <Figure>{index + 1}</Figure>
                            </span>
                            <span className="grid gap-0.5">
                                <span className="text-sm font-medium text-ink">{t(`b2b::company.steps.${step}.title`)}</span>
                                <span className="text-xs text-ink-muted">{t(`b2b::company.steps.${step}.body`)}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            </Card>

            <Card title={t('b2b::company.payment.title')} test="payment">
                <Payment page={page} />
            </Card>

            {approved ? null : (
                <Card title={t('b2b::company.before.title')} test="before-approval">
                    <p className="text-sm text-ink">{t('b2b::company.before.body')}</p>
                </Card>
            )}
        </aside>
    );
}

/**
 * How a company pays, by its state (amendment 14(h)): never online, the account only once approved,
 * and "temporarily unavailable" while the store has not filled in all three (amendment 13(c)).
 */
function Payment({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const [copied, setCopied] = useState(false);

    if (page.company?.status !== 'APPROVED') {
        return <p className="text-sm text-ink">{t('b2b::company.payment.before')}</p>;
    }

    const account = page.bankAccount;

    if (account === null) {
        return (
            <p className="text-sm text-ink" data-test="bank-transfer-off">
                {t('b2b::company.payment.off')}
            </p>
        );
    }

    return (
        <div className="grid gap-3" data-test="bank-account">
            <p className="text-sm text-ink">{t('b2b::company.payment.approved')}</p>
            <dl className="grid gap-2 text-sm">
                <div className="grid gap-0.5">
                    <dt className="text-xs text-ink-muted">{t('b2b::company.payment.iban')}</dt>
                    <dd className="flex flex-wrap items-center gap-2 text-ink">
                        <Figure>{account.iban}</Figure>
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            data-test="copy-iban"
                            onClick={() => {
                                void navigator.clipboard?.writeText(account.iban).then(() => setCopied(true));
                            }}
                        >
                            {copied ? t('b2b::company.payment.copied') : t('b2b::company.payment.copy')}
                        </Button>
                    </dd>
                </div>
                <div className="grid gap-0.5">
                    <dt className="text-xs text-ink-muted">{t('b2b::company.payment.bank')}</dt>
                    <dd className="text-ink">{account.bank}</dd>
                </div>
                <div className="grid gap-0.5">
                    <dt className="text-xs text-ink-muted">{t('b2b::company.payment.holder')}</dt>
                    <dd className="text-ink">{account.holder}</dd>
                </div>
            </dl>
        </div>
    );
}
