import { isolate } from '@/lib/bidi';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyApplicationData,
    CompanyTypeOptionData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, Figure, Line, nameOf, typeOf, useLocale, when } from './parts';

/*
| Every application the company sent, newest first (b2b.md §4.5, amendment 14(f)): one line each —
| its number, when it was sent, what came of it — opening onto what was sent, its papers, what it
| was told, and what it marked or asked for. No staff names (§3.1).
*/

const FIELDS = ['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const;

export function CompanyHistory({ history, documentTypes }: { history: CompanyApplicationData[]; documentTypes: CompanyTypeOptionData[] }) {
    const t = useTranslator();

    if (history.length === 0) {
        return null;
    }

    return (
        <Card title={t('b2b::company.section.history')} test="history">
            <ul className="grid gap-2">
                {history.map((application) => (
                    <li key={application.id}>
                        <SentApplication application={application} documentTypes={documentTypes} />
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/** One sent application, closed to a line until it is opened. */
export function SentApplication({
    application,
    documentTypes,
    open = false,
}: {
    application: CompanyApplicationData;
    documentTypes: CompanyTypeOptionData[];
    open?: boolean;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const values = application.values;
    const tone =
        application.state === 'APPROVED' ? 'text-good' : application.state === 'REJECTED' ? 'text-bad' : 'text-warn';

    const valueOf: Record<(typeof FIELDS)[number], string | null> = {
        name: values.name,
        company_type: typeOf(values, locale),
        cr_number: values.crNumber,
        tax_number: values.taxNumber,
        address: values.address,
    };

    const typeName = (id: string | null): string => {
        const known = documentTypes.find((type) => type.id === id);

        return known === undefined ? '' : nameOf(known, locale);
    };

    return (
        <details open={open} className="group rounded-md border border-line" data-test={`application-${application.reference}`}>
            <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                <span className="font-medium text-ink">
                    <Figure>{application.reference}</Figure>
                </span>
                <span className="text-xs text-ink-muted">
                    {t('b2b::company.sent_at', { date: isolate(when(application.submittedAt)) })}
                </span>
                <span className={['text-xs font-medium', tone].join(' ')}>{t(`b2b::company.state.${application.state}`)}</span>
            </summary>

            <div className="grid gap-4 border-t border-line px-3 py-3">
                {application.decisionReason !== null ? (
                    <dl className="grid gap-2">
                        <Line label={application.state === 'REJECTED' ? t('b2b::company.decision_reason') : t('b2b::company.decision_note')}>
                            {application.decisionReason}
                        </Line>
                        {application.decidedAt !== null ? (
                            <Line label={t('b2b::company.decided')}>
                                <Figure>{when(application.decidedAt)}</Figure>
                            </Line>
                        ) : null}
                    </dl>
                ) : null}

                <div className="grid gap-2">
                    <h3 className="text-xs font-semibold text-ink-muted">{t('b2b::company.sent_values')}</h3>
                    <dl className="grid gap-2">
                        {FIELDS.map((field) => (
                            <Line key={field} label={t(`b2b::company.field.${field}`)}>
                                {field === 'cr_number' || field === 'tax_number' ? <Figure>{valueOf[field]}</Figure> : valueOf[field]}
                            </Line>
                        ))}
                        {values.note !== null ? <Line label={t('b2b::company.section.note')}>{values.note}</Line> : null}
                    </dl>
                </div>

                {application.documents.length > 0 ? (
                    <ul className="flex flex-wrap gap-2">
                        {application.documents.map((document) => (
                            <li key={document.documentTypeId}>
                                <a
                                    href={link('storefront.company.file', { file: document.mediaId })}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex rounded-pill border border-good/40 bg-good-soft px-3 py-1 text-xs text-good hover:underline"
                                >
                                    {(locale === 'ar' ? document.documentTypeNameAr : document.documentTypeNameEn) ?? typeName(document.documentTypeId)}
                                </a>
                            </li>
                        ))}
                    </ul>
                ) : null}

                {application.flags.length > 0 ? (
                    <div className="grid gap-1">
                        <h3 className="text-xs font-semibold text-ink-muted">{t('b2b::company.asked_flagged')}</h3>
                        <ul className="list-inside list-disc text-sm text-ink">
                            {application.flags.map((flag) => (
                                <li key={flag.field ?? flag.documentTypeId ?? ''}>
                                    {flag.field !== null ? t(`b2b::company.field.${flag.field}`) : typeName(flag.documentTypeId)}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {application.requests.length > 0 ? (
                    <div className="grid gap-1">
                        <h3 className="text-xs font-semibold text-ink-muted">{t('b2b::company.asked_requests')}</h3>
                        <ul className="list-inside list-disc text-sm text-ink">
                            {application.requests.map((request) => (
                                <li key={request.id}>{request.label}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </details>
    );
}
