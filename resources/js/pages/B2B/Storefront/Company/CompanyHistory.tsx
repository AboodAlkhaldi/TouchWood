import type { ReactNode } from 'react';
import { ChevronDown, ExternalLink } from 'lucide-react';
import { Description } from '@/components/geist-only/Description';
import { Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyApplicationData, CompanyTypeOptionData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { applicationStateTone } from '../../status';
import { Card, figureOr, MARK, nameOf, Phrase, typeOf, useLocale, writtenOr } from './parts';

/*
| Every application the company sent, newest first (b2b.md §4.5, amendment 14(f)): one line each -
| its number, when it was sent, what came of it - opening onto what was sent, its papers, what it
| was told, and what it marked or asked for. No staff names (§3.1).
|
| On shadcn's parts with Geist's rules (frontend.md §1.11), as the staff screen shows the same
| applications: each line is shadcn's Collapsible in Geist's Collapse look, what came of it a Badge
| in the colours both sides share (../../status.ts), what was sent and decided Geist's Description
| (an em dash where a value is not given), and each paper an Item with Open File. The application
| waiting for a decision is the page's main content then, so it is shown open, never folded
| (Geist's Collapse: primary content is not hidden in one).
*/

const FIELDS = ['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const;

export function CompanyHistory({ history, documentTypes }: { history: CompanyApplicationData[]; documentTypes: CompanyTypeOptionData[] }) {
    const t = useTranslator();

    if (history.length === 0) {
        return null;
    }

    return (
        <Card title={t('b2b::company.section.history')} test="history">
            <div className="grid divide-y divide-line">
                {history.map((application, index) => (
                    <SentApplication key={application.id} application={application} previous={history[index + 1] ?? null} documentTypes={documentTypes} />
                ))}
            </div>
        </Card>
    );
}

/** One sent application: folded to its line, or - the one waiting for a decision - shown open. */
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
    /** Shown open, without a fold: the application the page is about. */
    open?: boolean;
}) {
    const line = <Line application={application} />;
    const body = <Body application={application} previous={previous} documentTypes={documentTypes} />;

    if (open) {
        return (
            <div className="grid gap-4" data-test={`application-${application.reference}`}>
                {line}
                {body}
            </div>
        );
    }

    return (
        <Collapsible className="group/collapse py-1" data-test={`application-${application.reference}`}>
            <CollapsibleTrigger asChild>
                <button type="button" className="flex w-full items-center justify-between gap-3 rounded-[var(--tw-radius-sm)] py-3 text-start outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                    {line}
                    <ChevronDown aria-hidden="true" className="size-4 shrink-0 text-ink-muted transition-transform group-data-[state=open]/collapse:rotate-180" />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pb-4">{body}</CollapsibleContent>
        </Collapsible>
    );
}

/** An application's line: its number, what came of it, and when it was sent. */
function Line({ application }: { application: CompanyApplicationData }) {
    const t = useTranslator();

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <bdi dir="ltr" className="text-label-14-mono text-ink">
                {application.reference}
            </bdi>
            <Badge className={tone(applicationStateTone(application.state))} data-test="application-state">
                {t(`b2b::company.state.${application.state}`)}
            </Badge>
            {application.submittedAt === null ? null : (
                <span className="text-copy-13 text-ink-muted">
                    <Phrase text={t('b2b::company.sent_at', { date: MARK })} moment={<Time value={application.submittedAt} focusable={false} inSentence />} />
                </span>
            )}
        </span>
    );
}

function Body({ application, previous, documentTypes }: { application: CompanyApplicationData; previous: CompanyApplicationData | null; documentTypes: CompanyTypeOptionData[] }) {
    const t = useTranslator();
    const locale = useLocale();
    const link = useLink();
    const values = application.values;

    const valueOf: Record<(typeof FIELDS)[number], ReactNode> = {
        name: writtenOr(values.name),
        company_type: writtenOr(typeOf(values, locale)),
        cr_number: figureOr(values.crNumber),
        tax_number: figureOr(values.taxNumber),
        address: writtenOr(values.address),
    };

    // A type since hidden from the form is not in the home store's list any more; it is still named.
    const typeName = (id: string | null): string => {
        const known = documentTypes.find((type) => type.id === id);

        return known === undefined ? t('b2b::company.document_gone') : nameOf(known, locale);
    };

    const asked = (requestId: string): string => previous?.requests.find((request) => request.id === requestId)?.label ?? '';
    const written = application.answers.filter((answer) => answer.mediaId === null);
    const files = application.answers.filter((answer) => answer.mediaId !== null);

    const open = (mediaId: string, test: string) => (
        // A new tab, so the page stays where it is; the link lasts 30 minutes (§1.4).
        <Button asChild variant="outline" size="sm">
            <a href={link('storefront.company.file', { file: mediaId })} target="_blank" rel="noopener noreferrer" data-test={test}>
                <ExternalLink aria-hidden="true" />
                {t('b2b::company.open')}
            </a>
        </Button>
    );

    return (
        <div className="grid gap-5 pt-1">
            {application.decisionReason !== null ? (
                <Description
                    columns={2}
                    items={[
                        {
                            title: application.state === 'REJECTED' ? t('b2b::company.decision_reason') : t('b2b::company.decision_note'),
                            content: writtenOr(application.decisionReason),
                        },
                        ...(application.decidedAt !== null ? [{ title: t('b2b::company.decided'), content: <Time value={application.decidedAt} mode="absolute" /> }] : []),
                    ]}
                />
            ) : null}

            <div className="grid gap-3">
                <h3 className="text-heading-14 text-ink">{t('b2b::company.sent_values')}</h3>
                <Description
                    items={[
                        ...FIELDS.map((field) => ({ title: t(`b2b::company.field.${field}`), content: valueOf[field] })),
                        ...(values.note !== null ? [{ title: t('b2b::company.section.note'), content: writtenOr(values.note) }] : []),
                    ]}
                />
            </div>

            {application.documents.length > 0 ? (
                <div className="grid gap-3">
                    <h3 className="text-heading-14 text-ink">{t('b2b::company.documents_sent')}</h3>
                    <ItemGroup className="material-base overflow-hidden">
                        {application.documents.map((document, index) => (
                            <div key={document.documentTypeId} role="listitem">
                                {index === 0 ? null : <ItemSeparator className="my-0" />}
                                <Item size="sm" className="rounded-none px-4" data-test={`sent-paper-${document.documentTypeId}`}>
                                    <ItemContent className="min-w-0">
                                        <ItemTitle className="text-label-14 text-ink">
                                            {(locale === 'ar' ? document.documentTypeNameAr : document.documentTypeNameEn) ?? typeName(document.documentTypeId)}
                                        </ItemTitle>
                                        <ItemDescription className="text-copy-13 text-ink-muted">
                                            <bdi>{document.fileName}</bdi>
                                        </ItemDescription>
                                    </ItemContent>
                                    <ItemActions>{open(document.mediaId, `open-sent-${document.documentTypeId}`)}</ItemActions>
                                </Item>
                            </div>
                        ))}
                    </ItemGroup>
                </div>
            ) : null}

            {/* What it answered: the requests of the application rejected before it (§1.2). */}
            {application.answers.length > 0 ? (
                <div className="grid gap-3">
                    <h3 className="text-heading-14 text-ink">{t('b2b::company.answers_sent')}</h3>
                    {/* A written answer is read whole (Geist's Description), never cut to a line. */}
                    {written.length === 0 ? null : <Description columns={1} items={written.map((answer) => ({ title: asked(answer.requestId), content: writtenOr(answer.text) }))} />}
                    {files.length === 0 ? null : (
                        <ItemGroup className="material-base overflow-hidden">
                            {files.map((answer, index) => (
                                <div key={answer.requestId} role="listitem">
                                    {index === 0 ? null : <ItemSeparator className="my-0" />}
                                    <Item size="sm" className="rounded-none px-4">
                                        <ItemContent className="min-w-0">
                                            <ItemTitle className="text-label-14 text-ink">{asked(answer.requestId)}</ItemTitle>
                                        </ItemContent>
                                        <ItemActions>{answer.mediaId === null ? null : open(answer.mediaId, `open-answer-${answer.requestId}`)}</ItemActions>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    )}
                </div>
            ) : null}

            {application.flags.length > 0 ? (
                <div className="grid gap-2">
                    <h3 className="text-heading-14 text-ink">{t('b2b::company.asked_flagged')}</h3>
                    <ul className="ms-5 list-disc text-copy-14 text-ink [&>li]:mt-1">
                        {application.flags.map((flag) => (
                            <li key={flag.field ?? flag.documentTypeId ?? ''}>{flag.field !== null ? t(`b2b::company.field.${flag.field}`) : typeName(flag.documentTypeId)}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {application.requests.length > 0 ? (
                <div className="grid gap-2">
                    <h3 className="text-heading-14 text-ink">{t('b2b::company.asked_requests')}</h3>
                    <ul className="ms-5 list-disc text-copy-14 text-ink [&>li]:mt-1">
                        {application.requests.map((request) => (
                            <li key={request.id}>{request.label}</li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
