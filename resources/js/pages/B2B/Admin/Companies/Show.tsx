import { useState, type ReactNode } from 'react';
import { Ellipsis, ExternalLink } from 'lucide-react';
import { AdminLayout } from '@/layouts/AdminLayout';
import {
    Badge,
    Button,
    Collapse,
    Description,
    Entity,
    EntityList,
    Menu,
    MenuDivider,
    MenuItem,
    Note,
    type DescriptionItem,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type {
    CompanyValuesData,
    StaffApplicationData,
    StaffCompanyPage,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { applicationStateLook, companyStatusLook, FormNote, nameIn, useLocale, when, type Locale } from '../shared';
import { ApproveModal, CorrectTypeModal, ReinstateModal, RejectModal, SuspendModal } from './Decisions';

/*
| One company, as staff review it (b2b.md §3.2, §4.6, amendment 19).
|
| Read only but for its buttons, each drawn only when B2B says this reader holds the job and it can
| happen next (StaffCompanyActionsForReader); every handler behind them asks again. Approve and Reject
| sit at the top while an application waits, Reinstate while suspended; Correct Company Type and
| Suspend in the actions menu, Suspend last (Geist's menu rules).
|
| Times are the home store's, written by the server (HANDOFF §4). The applications are the ones sent,
| newest first - never a draft - the newest open and the older ones folded.
*/

type Dialog = 'approve' | 'reject' | 'suspend' | 'reinstate' | 'correct' | null;

export default function Show({ company, holder, applications, actions, typeChoices }: StaffCompanyPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const waiting = applications[0]?.state === 'SUBMITTED' ? applications[0] : null;
    const values = company.values;

    const typeNote =
        waiting !== null && waiting.typeDeactivatedSinceSent
            ? t('b2b::admin_companies.application.type_changed.body', { type: typeOf(values, locale, t) })
            : null;

    const opened = (next: Dialog) => (open: boolean) => setDialog(open ? next : null);

    const top = (
        <div className="flex flex-wrap items-center gap-2">
            {actions.mayReject ? (
                <Button type="secondary" onClick={() => setDialog('reject')} data-test="reject">
                    {t('b2b::admin_companies.reject.button')}
                </Button>
            ) : null}
            {actions.mayApprove ? (
                <Button
                    onClick={() => setDialog('approve')}
                    disabledReason={actions.approveRefusal === null ? undefined : t(`b2b::admin_companies.approve.${actions.approveRefusal}`)}
                    data-test="approve"
                >
                    {t('b2b::admin_companies.approve.button')}
                </Button>
            ) : null}
            {actions.mayReinstate ? (
                <Button onClick={() => setDialog('reinstate')} data-test="reinstate">
                    {t('b2b::admin_companies.reinstate.button')}
                </Button>
            ) : null}
            {actions.mayCorrectType || actions.maySuspend ? (
                <Menu
                    trigger={
                        <Button type="tertiary" svgOnly aria-label={t('b2b::admin_companies.actions')} data-test="company-actions">
                            <Ellipsis className="size-4" />
                        </Button>
                    }
                >
                    {actions.mayCorrectType ? (
                        <MenuItem onSelect={() => setDialog('correct')} data-test="correct-type">
                            {t('b2b::admin_companies.correct.menu')}
                        </MenuItem>
                    ) : null}
                    {actions.mayCorrectType && actions.maySuspend ? <MenuDivider /> : null}
                    {actions.maySuspend ? (
                        <MenuItem type="error" onSelect={() => setDialog('suspend')} data-test="suspend">
                            {t('b2b::admin_companies.suspend.menu')}
                        </MenuItem>
                    ) : null}
                </Menu>
            ) : null}
        </div>
    );

    return (
        <AdminLayout title={values.name ?? ''} subtitle={t('b2b::admin_companies.subtitle_company')} action={top}>
            <div className="grid gap-6">
                <FormNote />

                <Section title={t('b2b::admin_companies.section.status')} test="company-status-section">
                    <Description
                        columns={3}
                        items={[
                            {
                                title: t('b2b::admin_companies.field.status'),
                                content: (
                                    <Badge variant={companyStatusLook(company.status)} data-test="company-status">
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
                            { title: t('b2b::admin_companies.field.changed_at'), content: ltr(when(company.statusChangedAt)) },
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
                                    { title: t('b2b::admin_companies.field.email'), content: ltr(holder.email) },
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

                <section className="material-base grid gap-1 px-5 py-4 sm:px-6" data-test="company-applications">
                    <h2 className="text-heading-20 text-ink">{t('b2b::admin_companies.section.applications')}</h2>
                    {applications.map((application, index) => (
                        <Collapse
                            key={application.id}
                            defaultOpen={index === 0}
                            title={
                                <span className="inline-flex flex-wrap items-center gap-2" data-test={`application-${application.reference}`}>
                                    <bdi dir="ltr" className="text-label-14-mono">
                                        {application.reference}
                                    </bdi>
                                    <Badge variant={applicationStateLook(application.state)} size="small">
                                        {t(`b2b::admin_companies.application.state.${application.state}`)}
                                    </Badge>
                                    <span className="tw-figure text-copy-13 text-ink-muted">
                                        <bdi dir="ltr">{when(application.submittedAt)}</bdi>
                                    </span>
                                </span>
                            }
                        >
                            <Application
                                application={application}
                                companyId={company.id}
                                mayOpen={actions.mayOpenDocuments}
                                typeNote={index === 0 ? typeNote : null}
                            />
                        </Collapse>
                    ))}
                </section>
            </div>

            {actions.mayApprove ? <ApproveModal open={dialog === 'approve'} onOpenChange={opened('approve')} companyId={company.id} typeNote={typeNote} /> : null}
            {actions.mayReject ? (
                <RejectModal
                    open={dialog === 'reject'}
                    onOpenChange={opened('reject')}
                    companyId={company.id}
                    papers={waiting?.documents ?? []}
                    locale={locale}
                />
            ) : null}
            {actions.maySuspend ? <SuspendModal open={dialog === 'suspend'} onOpenChange={opened('suspend')} companyId={company.id} /> : null}
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

function Section({ title, test, children }: { title: string; test: string; children: ReactNode }) {
    return (
        <section className="material-base grid gap-4 p-5 sm:p-6" data-test={test}>
            <h2 className="text-heading-20 text-ink">{title}</h2>
            {children}
        </section>
    );
}

/** A value that reads left to right — a number, an email, a time — kept whole on an Arabic page. */
function ltr(value: string | null): ReactNode {
    return value === null || value === '' ? null : (
        <bdi dir="ltr" className="tw-figure">
            {value}
        </bdi>
    );
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

/** One sent application: its decision, what it sent, its papers, and what its rejection marked and asked. */
function Application({
    application,
    companyId,
    mayOpen,
    typeNote,
}: {
    application: StaffApplicationData;
    companyId: string;
    mayOpen: boolean;
    typeNote: string | null;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const approved = application.state === 'APPROVED';
    const values = application.values;

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
                    { title: t('b2b::admin_companies.application.sent'), content: ltr(when(application.submittedAt)) },
                    { title: t('b2b::admin_companies.application.decided'), content: ltr(when(application.decidedAt)) },
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
                    <EntityList>
                        {application.documents.map((paper) => (
                            <Entity
                                key={paper.documentTypeId}
                                data-test={`paper-${paper.documentTypeId}`}
                                title={nameIn(locale, paper.documentTypeNameAr, paper.documentTypeNameEn) || t('b2b::admin_companies.application.paper')}
                                description={[paper.fileName, t('b2b::admin_companies.application.uploaded', { date: when(paper.uploadedAt) })]
                                    .filter((part) => part !== '')
                                    .join(' · ')}
                                actions={<OpenFile companyId={companyId} mediaId={paper.mediaId} mayOpen={mayOpen} />}
                            />
                        ))}
                    </EntityList>
                )}
            </div>

            {application.flags.length === 0 ? null : (
                <div className="grid gap-2" data-test="application-flags">
                    <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.flags')}</h3>
                    <ul className="list-disc ps-5 text-copy-14 text-ink">
                        {application.flags.map((flag) => (
                            <li key={flag.field ?? flag.documentTypeId ?? ''}>
                                {flag.field !== null
                                    ? t(`b2b::admin_companies.application.flag.${flag.field}`)
                                    : paperName(application, flag.documentTypeId, locale) || t('b2b::admin_companies.application.paper')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {application.requests.length === 0 ? null : (
                <div className="grid gap-2" data-test="application-requests">
                    <h3 className="text-heading-16 text-ink">{t('b2b::admin_companies.application.requests')}</h3>
                    <ul className="list-disc ps-5 text-copy-14 text-ink">
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
                    <EntityList>
                        {application.answers.map((answer) => (
                            <Entity
                                key={answer.requestId}
                                title={answer.label ?? t('b2b::admin_companies.application.answer')}
                                description={answer.text ?? answer.fileName ?? undefined}
                                actions={answer.mediaId === null ? undefined : <OpenFile companyId={companyId} mediaId={answer.mediaId} mayOpen={mayOpen} />}
                            />
                        ))}
                    </EntityList>
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
 * A paper opens in a new tab through a link that lasts 30 minutes, and the opening is audited
 * (amendment 10(f)). Without the job, the button stays, disabled, saying why.
 */
function OpenFile({ companyId, mediaId, mayOpen }: { companyId: string; mediaId: string; mayOpen: boolean }) {
    const t = useTranslator();

    return (
        <Button
            type="secondary"
            size="small"
            prefix={<ExternalLink className="size-4" />}
            disabledReason={mayOpen ? undefined : t('b2b::admin_companies.application.open_locked')}
            onClick={() => window.open(`/admin/companies/${companyId}/files/${mediaId}`, '_blank', 'noopener,noreferrer')}
            data-test={`open-${mediaId}`}
        >
            {t('b2b::admin_companies.application.open')}
        </Button>
    );
}
