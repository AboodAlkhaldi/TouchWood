import { ChevronDown } from 'lucide-react';
import { Badge, type BadgeVariant, Description } from '@/components/geist';
import { isolate } from '@/lib/bidi';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyApplicationData,
    CompanyTypeOptionData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, Figure, figureOr, nameOf, typeOf, useLocale, when, writtenOr } from './parts';

/*
| Every application the company sent, newest first (b2b.md §4.5, amendment 14(f)): one line each —
| its number, when it was sent, what came of it — opening onto what was sent, its papers, what it
| was told, and what it marked or asked for. No staff names (§3.1).
|
| On Geist's parts (frontend.md 1.10): what came of it is a Badge — amber while under review, green
| approved, red not approved — and what was sent and decided is Geist's Description, where a value
| not given is an em dash. Each line opens as Geist's Collapse does, a chevron that turns, but stays
| the browser's own <details>: its keyboard handling, and a closed one still found by find-in-page.
*/

const FIELDS = ['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const;

const STATE: Record<string, BadgeVariant> = {
    SUBMITTED: 'amber-subtle',
    APPROVED: 'green-subtle',
    REJECTED: 'red-subtle',
};

export function CompanyHistory({ history, documentTypes }: { history: CompanyApplicationData[]; documentTypes: CompanyTypeOptionData[] }) {
    const t = useTranslator();

    if (history.length === 0) {
        return null;
    }

    return (
        <Card title={t('b2b::company.section.history')} test="history">
            <ul className="grid gap-2">
                {history.map((application, index) => (
                    <li key={application.id}>
                        <SentApplication application={application} previous={history[index + 1] ?? null} documentTypes={documentTypes} />
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/** One sent application, closed to a line until it is opened. */
export function SentApplication({
    application,
    previous = null,
    documentTypes,
    open = false,
}: {
    application: CompanyApplicationData;
    /** The application sent before it, whose requests its answers reply to. */
    previous?: CompanyApplicationData | null;
    documentTypes: CompanyTypeOptionData[];
    open?: boolean;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const values = application.values;

    const valueOf: Record<(typeof FIELDS)[number], string | null> = {
        name: values.name,
        company_type: typeOf(values, locale),
        cr_number: values.crNumber,
        tax_number: values.taxNumber,
        address: values.address,
    };

    // A type since hidden from the form is not in the home store's list any more; it is still named.
    const typeName = (id: string | null): string => {
        const known = documentTypes.find((type) => type.id === id);

        return known === undefined ? t('b2b::company.document_gone') : nameOf(known, locale);
    };

    return (
        <details open={open} className="group rounded-[var(--tw-radius)] border border-line" data-test={`application-${application.reference}`}>
            <summary className="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-label-14 [&::-webkit-details-marker]:hidden">
                <span className="font-medium text-ink">
                    <Figure>{application.reference}</Figure>
                </span>
                <span className="text-copy-13 text-ink-muted">
                    {t('b2b::company.sent_at', { date: isolate(when(application.submittedAt)) })}
                </span>
                <span className="ms-auto flex items-center gap-2">
                    <Badge variant={STATE[application.state] ?? 'gray-subtle'}>{t(`b2b::company.state.${application.state}`)}</Badge>
                    <ChevronDown aria-hidden="true" className="size-4 text-ink-muted transition-transform group-open:rotate-180" />
                </span>
            </summary>

            <div className="grid gap-5 border-t border-line px-3 py-4">
                {application.decisionReason !== null ? (
                    <Description
                        columns={1}
                        items={[
                            {
                                title: application.state === 'REJECTED' ? t('b2b::company.decision_reason') : t('b2b::company.decision_note'),
                                content: writtenOr(application.decisionReason),
                            },
                            ...(application.decidedAt !== null
                                ? [{ title: t('b2b::company.decided'), content: figureOr(when(application.decidedAt)) }]
                                : []),
                        ]}
                    />
                ) : null}

                <div className="grid gap-3">
                    <h3 className="text-heading-14 text-ink">{t('b2b::company.sent_values')}</h3>
                    <Description
                        items={[
                            ...FIELDS.map((field) => ({
                                title: t(`b2b::company.field.${field}`),
                                content: field === 'cr_number' || field === 'tax_number' ? figureOr(valueOf[field]) : writtenOr(valueOf[field]),
                            })),
                            ...(values.note !== null ? [{ title: t('b2b::company.section.note'), content: writtenOr(values.note) }] : []),
                        ]}
                    />
                </div>

                {application.documents.length > 0 ? (
                    <ul className="flex flex-wrap gap-x-4 gap-y-2">
                        {application.documents.map((document) => (
                            <li key={document.documentTypeId}>
                                <a
                                    href={link('storefront.company.file', { file: document.mediaId })}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-label-14 text-brand hover:underline"
                                >
                                    {(locale === 'ar' ? document.documentTypeNameAr : document.documentTypeNameEn) ?? typeName(document.documentTypeId)}
                                </a>
                            </li>
                        ))}
                    </ul>
                ) : null}

                {/* What it answered: the requests of the application rejected before it (§1.2). */}
                {application.answers.length > 0 ? (
                    <div className="grid gap-2">
                        <h3 className="text-heading-14 text-ink">{t('b2b::company.answers_sent')}</h3>
                        <ul className="grid gap-3 text-copy-14 text-ink">
                            {application.answers.map((answer) => (
                                <li key={answer.requestId} className="grid justify-items-start gap-0.5">
                                    <span>{previous?.requests.find((request) => request.id === answer.requestId)?.label ?? ''}</span>
                                    {answer.text !== null ? <span className="whitespace-pre-line text-ink-muted">{answer.text}</span> : null}
                                    {answer.mediaId !== null ? (
                                        <a
                                            href={link('storefront.company.file', { file: answer.mediaId })}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-label-14 text-brand hover:underline"
                                        >
                                            {t('b2b::company.open')}
                                        </a>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {application.flags.length > 0 ? (
                    <div className="grid gap-2">
                        <h3 className="text-heading-14 text-ink">{t('b2b::company.asked_flagged')}</h3>
                        <ul className="list-inside list-disc text-copy-14 text-ink">
                            {application.flags.map((flag) => (
                                <li key={flag.field ?? flag.documentTypeId ?? ''}>
                                    {flag.field !== null ? t(`b2b::company.field.${flag.field}`) : typeName(flag.documentTypeId)}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {application.requests.length > 0 ? (
                    <div className="grid gap-2">
                        <h3 className="text-heading-14 text-ink">{t('b2b::company.asked_requests')}</h3>
                        <ul className="list-inside list-disc text-copy-14 text-ink">
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
