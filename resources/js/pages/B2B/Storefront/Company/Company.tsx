import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyApplicationData,
    CompanyDraftData,
    CompanyPage,
    CompanyStatusData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { CompanyForm, DiscardConfirmation } from './CompanyForm';
import { CompanyHistory, SentApplication } from './CompanyHistory';
import { CompanySide } from './CompanySide';
import { Card, Figure, Line, StatusBox, typeOf, useLocale } from './parts';

/*
| F11 — the company's own page (b2b.md §4.5, amendment 14; the design's company screen).
|
| Two columns: the status box and, under it, the form or what was sent; beside them, what happens
| after sending, how a company pays, and what it may do before approval.
|
| **Before the first send there is no company, only a draft** (§1.1), and the page shows the draft
| alone — not sent yet, what is still missing, Continue or Discard. Once there is a company, what it
| sees follows its status: under review, the application it sent; approved, its details, its
| address and Change company details; rejected, why, and Apply again; suspended, why, and nothing
| to change. Every application it sent is listed under that, newest first, with its number.
*/

export default function Company(page: CompanyPage) {
    const t = useTranslator();

    return (
        <AccountLayout title={t('b2b::company.title')} subtitle={t('b2b::company.subtitle')} page="b2b.company">
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="grid content-start gap-6">
                    <FormError />
                    {page.company === null ? <BeforeACompany page={page} /> : <WithACompany page={page} company={page.company} />}
                    <CompanyHistory history={page.history} documentTypes={page.documentTypes} />
                </div>

                <CompanySide page={page} />
            </div>
        </AccountLayout>
    );
}

/** Nothing sent yet: the draft alone, or the way to start one (§4.3). */
function BeforeACompany({ page }: { page: CompanyPage }) {
    const t = useTranslator();

    return (
        <>
            <StatusBox tone="plain" title={t('b2b::company.status.new.title')}>
                <p>{t('b2b::company.status.new.body')}</p>
                {page.stage === 'EMAIL_NOT_CONFIRMED' ? (
                    <p className="text-warn" data-test="confirm-email-first">
                        {t('b2b::company.status.confirm_email')}
                    </p>
                ) : null}
                {page.draft !== null ? <Missing page={page} draft={page.draft} /> : null}
            </StatusBox>

            {page.draft === null ? (
                <StartButton label={t('b2b::company.start')} />
            ) : (
                <CompanyForm page={page} draft={page.draft} lastSent={null} changing={false} />
            )}
        </>
    );
}

function WithACompany({ page, company }: { page: CompanyPage; company: CompanyStatusData }) {
    const t = useTranslator();
    const lastSent = page.history[0] ?? null;

    switch (company.status) {
        case 'PENDING':
            return (
                <>
                    <StatusBox tone="warn" title={t('b2b::company.status.pending.title')}>
                        <p>{t('b2b::company.status.pending.body')}</p>
                    </StatusBox>
                    {lastSent !== null ? (
                        <Card title={t('b2b::company.section.sent')} test="sent">
                            <SentApplication application={lastSent} documentTypes={page.documentTypes} open />
                        </Card>
                    ) : null}
                </>
            );

        case 'APPROVED':
            return (
                <>
                    <StatusBox tone="good" title={t('b2b::company.status.approved.title')}>
                        <p>{t('b2b::company.status.approved.body')}</p>
                    </StatusBox>
                    <CompanyCard company={company} reference={lastApproved(page.history)?.reference ?? null} />
                    {page.draft === null ? (
                        <>
                            <AddressForm saved={company.details.address ?? ''} />
                            <StartButton label={t('b2b::company.change')} variant="outline" test="change" />
                        </>
                    ) : (
                        <CompanyForm page={page} draft={page.draft} lastSent={null} changing />
                    )}
                </>
            );

        case 'REJECTED':
            return (
                <>
                    <StatusBox tone="bad" title={t('b2b::company.status.rejected.title')}>
                        <p data-test="status-reason">{t('b2b::company.status.rejected.body', { reason: company.statusReason ?? '' })}</p>
                    </StatusBox>
                    {page.draft === null ? (
                        <StartButton label={t('b2b::company.apply_again')} test="apply-again" />
                    ) : (
                        <CompanyForm
                            page={page}
                            draft={page.draft}
                            lastSent={lastSent?.state === 'REJECTED' ? lastSent : null}
                            changing={false}
                        />
                    )}
                </>
            );

        default:
            // SUSPENDED: nothing changes until staff reinstate it (§1.1) — but an unsent draft may go.
            return (
                <>
                    <StatusBox tone="bad" title={t('b2b::company.status.suspended.title')}>
                        <p data-test="status-reason">{t('b2b::company.status.suspended.body', { reason: company.statusReason ?? '' })}</p>
                    </StatusBox>
                    <CompanyCard company={company} reference={null} />
                    {page.draft !== null ? <FrozenDraft /> : null}
                </>
            );
    }
}

/** What is still missing before a first send can go (§4.5). */
function Missing({ page, draft }: { page: CompanyPage; draft: CompanyDraftData }) {
    const t = useTranslator();
    const values = draft.values;
    const empty = [
        values.name === null ? t('b2b::company.field.name') : null,
        values.companyTypeId === null && values.companyTypeOther === null ? t('b2b::company.field.company_type') : null,
        values.crNumber === null ? t('b2b::company.field.cr_number') : null,
        values.taxNumber === null ? t('b2b::company.field.tax_number') : null,
        values.address === null ? t('b2b::company.field.address') : null,
    ].filter((item): item is string => item !== null);
    const documents = page.documentTypes.filter(
        (type) => type.required && !type.greyed && !draft.documents.some((document) => document.documentTypeId === type.id),
    ).length;
    const items = [...empty, ...(documents > 0 ? [t('b2b::company.missing_documents', { count: documents })] : [])];

    return items.length === 0 ? null : (
        <p className="text-ink-muted" data-test="missing">
            {t('b2b::company.missing', { items: items.join('، ') })}
        </p>
    );
}

/** The company as it stands: what it sent last, read-only. */
function CompanyCard({ company, reference }: { company: CompanyStatusData; reference: string | null }) {
    const t = useTranslator();
    const locale = useLocale();
    const details = company.details;

    return (
        <Card title={t('b2b::company.section.company')} test="company">
            <dl className="grid gap-2">
                {reference !== null ? (
                    <Line label={t('b2b::company.reference')}>
                        <Figure>{reference}</Figure>
                    </Line>
                ) : null}
                <Line label={t('b2b::company.field.name')}>{details.name}</Line>
                <Line label={t('b2b::company.field.company_type')}>{typeOf(details, locale)}</Line>
                <Line label={t('b2b::company.field.cr_number')}>
                    <Figure>{details.crNumber}</Figure>
                </Line>
                <Line label={t('b2b::company.field.tax_number')}>
                    <Figure>{details.taxNumber}</Figure>
                </Line>
                <Line label={t('b2b::company.field.address')}>{details.address}</Line>
            </dl>
        </Card>
    );
}

/** The address alone, saved at once and without review (§1.1, amendment 14(e)). */
function AddressForm({ saved }: { saved: string }) {
    const t = useTranslator();
    const link = useLink();
    const form = useForm({ address: saved });

    return (
        <Card title={t('b2b::company.section.address')} hint={t('b2b::company.address_hint')} test="address">
            <form
                className="grid gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(link('storefront.company.address'), { preserveScroll: true });
                }}
            >
                <Field id="company-address" label={t('b2b::company.field.address')} error={form.errors.address}>
                    <textarea
                        id="company-address"
                        name="address"
                        rows={3}
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        className="w-full rounded-md border border-line-strong bg-surface p-3 text-sm text-ink"
                    />
                </Field>
                <div>
                    <Button type="submit" variant="outline" disabled={form.processing}>
                        {t('b2b::company.address_save')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function StartButton({ label, variant = 'default', test = 'start' }: { label: string; variant?: 'default' | 'outline'; test?: string }) {
    const link = useLink();

    return (
        <div>
            <Button type="button" variant={variant} data-test={test} onClick={() => router.post(link('storefront.company.start'), {}, { preserveScroll: true })}>
                {label}
            </Button>
        </div>
    );
}

/** A suspended company's unsent draft: nothing in it may change, and it may only go (§1.1). */
function FrozenDraft() {
    const t = useTranslator();
    const [confirming, setConfirming] = useState(false);

    return (
        <Card test="frozen-draft">
            <p className="text-sm text-ink">{t('b2b::company.suspended_draft')}</p>
            <div>
                <Button type="button" variant="outline" data-test="discard" onClick={() => setConfirming((open) => !open)}>
                    {t('b2b::company.discard')}
                </Button>
            </div>
            {confirming ? <DiscardConfirmation onCancel={() => setConfirming(false)} /> : null}
        </Card>
    );
}

function lastApproved(history: CompanyApplicationData[]): CompanyApplicationData | undefined {
    return history.find((application) => application.state === 'APPROVED');
}
