import { useRef, useState, type ReactNode } from 'react';
import { ChevronDown, ExternalLink, MoreHorizontal } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Time } from '@/components/Time';
import { Description, type DescriptionItem } from '@/components/geist-only/Description';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyValuesData, StaffApplicationData, StaffCompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { applicationStateTone, companyStatusTone } from '../../status';
import { nameIn, useLocale, type Locale } from '../shared';
import { ApproveModal, CorrectTypeModal, ReinstateModal, RejectModal, SuspendModal } from './Decisions';

/*
| One company, as staff review it (b2b.md §3.2, §4.6, amendments 21 and 23), on shadcn's parts with
| Geist's rules (frontend.md §1.11).
|
| Read only but for its buttons, each drawn only when B2B says this reader holds the job and it can
| happen next (StaffCompanyActionsForReader); every handler behind them asks again. Approve and Reject
| sit at the top while an application waits, Reinstate while suspended; Correct Company Type and
| Suspend in the ⋯ menu, Suspend last after a divider (Geist's menu rules; confirmed by the owner,
| 2026-10-03).
|
| Times are moments in the store being worked in (amendment 23(b)): shadcn's HoverCard through Time,
| the full moment on the page, its zone and UTC on hover. The applications are the ones sent, newest
| first - never a draft - each one of shadcn's Collapsibles, the newest open. A paper is an Item with
| its Open File; a written answer is Geist's Description, so a long one is read whole.
*/

type Dialog = 'approve' | 'reject' | 'suspend' | 'reinstate' | 'correct' | null;

export default function Show({ company, holder, applications, actions, typeChoices }: StaffCompanyPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const more = useRef<HTMLButtonElement>(null);
    const waiting = applications[0]?.state === 'SUBMITTED' ? applications[0] : null;
    const values = company.values;

    const typeNote = waiting !== null && waiting.typeDeactivatedSinceSent ? t('b2b::admin_companies.application.type_changed.body', { type: typeOf(values, locale, t) }) : null;

    const opened = (next: Dialog) => (open: boolean) => setDialog(open ? next : null);

    const top = (
        <div className="flex flex-wrap items-center gap-2">
            {actions.mayReject ? (
                <Button type="button" variant="outline" onClick={() => setDialog('reject')} data-test="reject">
                    {t('b2b::admin_companies.reject.button')}
                </Button>
            ) : null}
            {actions.mayApprove ? (
                <ActionButton
                    onClick={() => setDialog('approve')}
                    disabledReason={actions.approveRefusal === null ? undefined : t(`b2b::admin_companies.approve.${actions.approveRefusal}`)}
                    data-test="approve"
                >
                    {t('b2b::admin_companies.approve.button')}
                </ActionButton>
            ) : null}
            {actions.mayReinstate ? (
                <ActionButton onClick={() => setDialog('reinstate')} data-test="reinstate">
                    {t('b2b::admin_companies.reinstate.button')}
                </ActionButton>
            ) : null}
            {actions.mayCorrectType || actions.maySuspend ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button ref={more} type="button" variant="outline" size="icon" aria-label={t('b2b::admin_companies.actions')} title={t('b2b::admin_companies.actions')} data-test="company-actions">
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="min-w-56">
                        {actions.mayCorrectType ? (
                            <DropdownMenuItem onSelect={() => setDialog('correct')} data-test="correct-type">
                                {t('b2b::admin_companies.correct.menu')}
                            </DropdownMenuItem>
                        ) : null}
                        {actions.mayCorrectType && actions.maySuspend ? <DropdownMenuSeparator /> : null}
                        {actions.maySuspend ? (
                            <DropdownMenuItem variant="destructive" onSelect={() => setDialog('suspend')} data-test="suspend">
                                {t('b2b::admin_companies.suspend.menu')}
                            </DropdownMenuItem>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </div>
    );

    return (
        <AdminLayout title={values.name ?? ''} subtitle={t('b2b::admin_companies.subtitle_company')} action={top}>
            <div className="grid gap-6">
                <FormError />

                <Section title={t('b2b::admin_companies.section.status')} test="company-status-section">
                    <Description
                        columns={3}
                        items={[
                            {
                                title: t('b2b::admin_companies.field.status'),
                                content: (
                                    <Badge className={tone(companyStatusTone(company.status))} data-test="company-status">
                                        {t(`b2b::admin_companies.status.${company.status}`)}
                                    </Badge>
                                ),
                            },
                            {
                                title: t('b2b::admin_companies.field.ordering'),
                                content: company.mayOrder ? t('b2b::admin_companies.ordering.open') : t('b2b::admin_companies.ordering.closed'),
                            },
                            { title: t('b2b::admin_companies.field.store'), content: company.storeName },
                            {
                                title: t('b2b::admin_companies.field.reason'),
                                content: company.statusReason === null ? null : <span className="whitespace-pre-line">{company.statusReason}</span>,
                                'data-test': 'company-reason',
                            },
                            { title: t('b2b::admin_companies.field.changed_at'), content: moment(company.statusChangedAt) },
                            { title: t('b2b::admin_companies.field.changed_by'), content: company.statusChangedBy },
                        ]}
                    />
                </Section>

                <Section title={t('b2b::admin_companies.section.details')} test="company-details">
                    <Description columns={2} items={details(values, locale, t)} />
                    {values.companyTypeOther !== null ? (
                        <Note variant="warning" size="small" data-test="type-other">
                            {t('b2b::admin_companies.other_hint')}
                        </Note>
                    ) : null}
                </Section>

                <Section title={t('b2b::admin_companies.section.holder')} test="company-holder">
                    {holder === null ? (
                        <Description items={[{ title: t('b2b::admin_companies.field.holder_name'), content: null }]} />
                    ) : (
                        <>
                            {holder.anonymized ? (
                                <Note variant="secondary" size="small" label={t('b2b::admin_companies.holder_label')}>
                                    {t('b2b::admin_companies.holder_erased')}
                                </Note>
                            ) : null}
                            <Description
                                columns={2}
                                items={[
                                    { title: t('b2b::admin_companies.field.holder_name'), content: holder.name },
                                    { title: t('b2b::admin_companies.field.email'), content: ltr(holder.email, false) },
                                    { title: t('b2b::admin_companies.field.phone'), content: ltr(holder.phone ?? '') },
                                    {
                                        title: t('b2b::admin_companies.field.email_confirmed'),
                                        content: holder.emailVerified ? t('b2b::admin_companies.confirmed') : t('b2b::admin_companies.not_confirmed'),
                                    },
                                    {
                                        title: t('b2b::admin_companies.field.phone_confirmed'),
                                        content: holder.phoneVerified ? t('b2b::admin_companies.confirmed') : t('b2b::admin_companies.not_confirmed'),
                                    },
                                ]}
                            />
                        </>
                    )}
                </Section>

                <Section title={t('b2b::admin_companies.section.applications')} test="company-applications">
                    <div className="grid divide-y divide-line">
                        {applications.map((application, index) => (
                            <ApplicationCollapse
                                key={application.id}
                                application={application}
                                defaultOpen={index === 0}
                                companyId={company.id}
                                mayOpen={actions.mayOpenDocuments}
                                typeNote={index === 0 ? typeNote : null}
                            />
                        ))}
                    </div>
                </Section>
            </div>

            {actions.mayApprove ? <ApproveModal open={dialog === 'approve'} onOpenChange={opened('approve')} companyId={company.id} typeNote={typeNote} /> : null}
            {actions.mayReject ? (
                <RejectModal open={dialog === 'reject'} onOpenChange={opened('reject')} companyId={company.id} typeNote={typeNote} papers={waiting?.documents ?? []} locale={locale} />
            ) : null}
            {actions.maySuspend ? <SuspendModal open={dialog === 'suspend'} onOpenChange={opened('suspend')} companyId={company.id} companyName={values.name ?? ''} returnFocusTo={more} /> : null}
            {actions.mayReinstate ? (
                <ReinstateModal
                    open={dialog === 'reinstate'}
                    onOpenChange={opened('reinstate')}
                    companyId={company.id}
                    returnsTo={company.statusBeforeSuspension === null ? '' : t(`b2b::admin_companies.status.${company.statusBeforeSuspension}`)}
                />
            ) : null}
            {actions.mayCorrectType ? (
                <CorrectTypeModal
                    open={dialog === 'correct'}
                    onOpenChange={opened('correct')}
                    companyId={company.id}
                    returnFocusTo={more}
                    choices={typeChoices}
                    mayChooseOther={actions.mayChooseOther}
                    currentTypeId={values.companyTypeId}
                    currentOther={values.companyTypeOther}
                    locale={locale}
                />
            ) : null}
        </AdminLayout>
    );
}

type Translate = (key: string, values?: Record<string, string | number>) => string;

/** A section of the page: one of shadcn's Cards, in Geist's material. */
function Section({ title, test, children }: { title: string; test: string; children: ReactNode }) {
    return (
        <Card className="material-base gap-0 border-0 py-0" data-test={test}>
            <CardHeader className="px-5 pt-5 pb-4 sm:px-6">
                <CardTitle>
                    <h2 className="text-heading-20 text-ink">{title}</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 px-5 pb-5 sm:px-6">{children}</CardContent>
        </Card>
    );
}

/**
 * A value that reads left to right — a number, an email — kept whole on an Arabic page; in the
 * figures' face unless it is words, as an email is.
 */
function ltr(value: string | null, figures = true): ReactNode {
    return value === null || value === '' ? null : (
        <bdi dir="ltr" className={figures ? 'tw-figure' : undefined}>
            {value}
        </bdi>
    );
}

/** A moment on a detail page: whole, in the store being worked in (amendment 23(b)). */
function moment(at: string | null): ReactNode {
    return at === null ? null : <Time value={at} mode="absolute" />;
}

/** A listed type's name, or "Other" with the company's own words (§1.3). */
function typeOf(values: CompanyValuesData, locale: Locale, t: Translate): string {
    if (values.companyTypeOther !== null) {
        return t('b2b::admin_companies.other_words', { words: values.companyTypeOther });
    }

    return nameIn(locale, values.companyTypeNameAr, values.companyTypeNameEn);
}

function details(values: CompanyValuesData, locale: Locale, t: Translate): DescriptionItem[] {
    return [
        { title: t('b2b::admin_companies.field.name'), content: values.name, 'data-test': 'value-name' },
        { title: t('b2b::admin_companies.field.type'), content: typeOf(values, locale, t), 'data-test': 'value-type' },
        { title: t('b2b::admin_companies.field.cr_number'), content: ltr(values.crNumber) },
        { title: t('b2b::admin_companies.field.tax_number'), content: ltr(values.taxNumber) },
        {
            title: t('b2b::admin_companies.field.address'),
            content: values.address === null ? null : <span className="whitespace-pre-line">{values.address}</span>,
        },
    ];
}

/** One sent application, folded or open: shadcn's Collapsible in Geist's Collapse look. */
function ApplicationCollapse({
    application,
    defaultOpen,
    companyId,
    mayOpen,
    typeNote,
}: {
    application: StaffApplicationData;
    defaultOpen: boolean;
    companyId: string;
    mayOpen: boolean;
    typeNote: string | null;
}) {
    const t = useTranslator();

    return (
        <Collapsible defaultOpen={defaultOpen} className="group/collapse py-1">
            <CollapsibleTrigger asChild>
                <button type="button" className="flex w-full items-center justify-between gap-3 rounded-[var(--tw-radius-sm)] py-3 text-start outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                    <span className="inline-flex flex-wrap items-center gap-2" data-test={`application-${application.reference}`}>
                        <bdi dir="ltr" className="text-label-14-mono text-ink">
                            {application.reference}
                        </bdi>
                        <Badge className={tone(applicationStateTone(application.state))}>{t(`b2b::admin_companies.application.state.${application.state}`)}</Badge>
                        <span className="text-copy-13 text-ink-muted">{application.submittedAt === null ? null : <Time value={application.submittedAt} focusable={false} />}</span>
                    </span>
                    <ChevronDown aria-hidden="true" className="size-4 shrink-0 text-ink-muted transition-transform group-data-[state=open]/collapse:rotate-180" />
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="pb-4">
                <Application application={application} companyId={companyId} mayOpen={mayOpen} typeNote={typeNote} />
            </CollapsibleContent>
        </Collapsible>
    );
}

/** One sent application: its decision, what it sent, its papers, and what its rejection marked and asked. */
function Application({ application, companyId, mayOpen, typeNote }: { application: StaffApplicationData; companyId: string; mayOpen: boolean; typeNote: string | null }) {
    const t = useTranslator();
    const locale = useLocale();
    const approved = application.state === 'APPROVED';
    const values = application.values;
    const written = application.answers.filter((answer) => !answer.isFile);
    const files = application.answers.filter((answer) => answer.isFile);

    return (
        <div className="grid gap-5 pt-1">
            {typeNote === null ? null : (
                <Note variant="warning" size="small" label={t('b2b::admin_companies.application.type_changed.label')} data-test="type-changed">
                    {typeNote}
                </Note>
            )}

            <Description
                columns={3}
                items={[
                    { title: t('b2b::admin_companies.application.reference'), content: ltr(application.reference) },
                    { title: t('b2b::admin_companies.application.sent'), content: moment(application.submittedAt) },
                    { title: t('b2b::admin_companies.application.decided'), content: moment(application.decidedAt) },
                    { title: t('b2b::admin_companies.application.decided_by'), content: application.decidedBy, 'data-test': 'decided-by' },
                    {
                        title: approved ? t('b2b::admin_companies.application.approval_note') : t('b2b::admin_companies.application.reason'),
                        content: application.decisionReason === null ? null : <span className="whitespace-pre-line">{application.decisionReason}</span>,
                        'data-test': 'decision-reason',
                    },
                    {
                        title: t('b2b::admin_companies.application.customer_note'),
                        content: values.note === null ? null : <span className="whitespace-pre-line">{values.note}</span>,
                    },
                ]}
            />

            <div className="grid gap-3">
                <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.values')}</h3>
                <Description columns={2} items={details(values, locale, t)} />
            </div>

            <div className="grid gap-3">
                <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.papers')}</h3>
                {application.documents.length === 0 ? (
                    <p className="text-copy-14 text-ink-muted">{t('b2b::admin_companies.application.no_papers')}</p>
                ) : (
                    <ItemGroup className="material-base overflow-hidden">
                        {application.documents.map((paper, index) => (
                            <div key={paper.documentTypeId} role="listitem">
                                {index === 0 ? null : <ItemSeparator className="my-0" />}
                                <Item size="sm" className="rounded-none px-4" data-test={`paper-${paper.documentTypeId}`}>
                                    <ItemContent className="min-w-0">
                                        <ItemTitle className="text-label-14 text-ink">{nameIn(locale, paper.documentTypeNameAr, paper.documentTypeNameEn) || t('b2b::admin_companies.application.paper')}</ItemTitle>
                                        <ItemDescription className="text-copy-13 text-ink-muted">
                                            {paper.fileName === '' ? null : <bdi>{paper.fileName}</bdi>}
                                            {paper.fileName === '' ? null : ' · '}
                                            {t('b2b::admin_companies.application.uploaded_on')} <Time value={paper.uploadedAt} focusable={false} inSentence />
                                        </ItemDescription>
                                    </ItemContent>
                                    <ItemActions>
                                        <OpenFile companyId={companyId} mediaId={paper.mediaId} mayOpen={mayOpen} test={`open-${paper.documentTypeId}`} />
                                    </ItemActions>
                                </Item>
                            </div>
                        ))}
                    </ItemGroup>
                )}
            </div>

            {application.flags.length === 0 ? null : (
                <div className="grid gap-2" data-test="application-flags">
                    <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.flags')}</h3>
                    <ul className="ms-5 list-disc text-copy-14 text-ink [&>li]:mt-1">
                        {application.flags.map((flag) => (
                            <li key={flag.field ?? flag.documentTypeId ?? ''}>
                                {flag.field !== null ? t(`b2b::admin_companies.application.flag.${flag.field}`) : paperName(application, flag.documentTypeId, locale) || t('b2b::admin_companies.application.paper')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {application.requests.length === 0 ? null : (
                <div className="grid gap-2" data-test="application-requests">
                    <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.requests')}</h3>
                    <ul className="ms-5 list-disc text-copy-14 text-ink [&>li]:mt-1">
                        {application.requests.map((request) => (
                            <li key={request.id}>
                                {request.label} <span className="text-ink-muted">({t(`b2b::admin_companies.application.kind.${request.kind}`)})</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {application.answers.length === 0 ? null : (
                <div className="grid gap-3" data-test="application-answers">
                    <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.answers')}</h3>
                    {/* A written answer is read whole (Geist's Description), never cut to a line. */}
                    {written.length === 0 ? null : (
                        <Description
                            columns={1}
                            items={written.map((answer) => ({
                                title: answer.label ?? t('b2b::admin_companies.application.answer'),
                                content: answer.text === null ? null : <span className="whitespace-pre-line">{answer.text}</span>,
                            }))}
                        />
                    )}
                    {files.length === 0 ? null : (
                        <ItemGroup className="material-base overflow-hidden">
                            {files.map((answer, index) => (
                                <div key={answer.requestId} role="listitem">
                                    {index === 0 ? null : <ItemSeparator className="my-0" />}
                                    <Item size="sm" className="rounded-none px-4">
                                        <ItemContent className="min-w-0">
                                            <ItemTitle className="text-label-14 text-ink">{answer.label ?? t('b2b::admin_companies.application.answer')}</ItemTitle>
                                            {answer.fileName === null ? null : (
                                                <ItemDescription className="text-copy-13 text-ink-muted">
                                                    <bdi>{answer.fileName}</bdi>
                                                </ItemDescription>
                                            )}
                                        </ItemContent>
                                        <ItemActions>
                                            <OpenFile companyId={companyId} mediaId={answer.mediaId} mayOpen={mayOpen} test={`open-answer-${answer.requestId}`} />
                                        </ItemActions>
                                    </Item>
                                </div>
                            ))}
                        </ItemGroup>
                    )}
                </div>
            )}
        </div>
    );
}

function paperName(application: StaffApplicationData, documentTypeId: string | null, locale: Locale): string {
    const paper = application.documents.find((each) => each.documentTypeId === documentTypeId);

    return paper === undefined ? '' : nameIn(locale, paper.documentTypeNameAr, paper.documentTypeNameEn);
}

/**
 * A paper opens through a link that lasts 30 minutes, and the opening is audited (amendment 10(f)).
 * Without the job the file's id never reaches the page (amendment 8(c)): the button stays, out of
 * reach, saying why, and is named after the paper's type or the request rather than the file.
 */
function OpenFile({ companyId, mediaId, mayOpen, test }: { companyId: string; mediaId: string | null; mayOpen: boolean; test: string }) {
    const t = useTranslator();

    if (!mayOpen || mediaId === null) {
        return (
            <ActionButton variant="outline" size="sm" disabledReason={t('b2b::admin_companies.application.open_locked')} data-test={test}>
                <ExternalLink aria-hidden="true" />
                {t('b2b::admin_companies.application.open')}
            </ActionButton>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <a href={`/admin/companies/${companyId}/files/${mediaId}`} target="_blank" rel="noopener noreferrer" data-test={test}>
                <ExternalLink aria-hidden="true" />
                {t('b2b::admin_companies.application.open')}
            </a>
        </Button>
    );
}
