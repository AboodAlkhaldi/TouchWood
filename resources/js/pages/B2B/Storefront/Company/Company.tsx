import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, type BadgeVariant, Button, Description, Entity, EntityList, Note } from '@/components/geist';
import { FormError } from '@/components/FormError';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyApplicationData,
    CompanyDraftData,
    CompanyPage,
    CompanyStatusData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { CompanyForm, DiscardModal, missingItems } from './CompanyForm';
import { CompanyHistory, SentApplication } from './CompanyHistory';
import { CompanySide } from './CompanySide';
import { AddressPicker, type Look } from './fields';
import { Card, Figure, figureOr, StatusBox, typeOf, useLocale, writtenOr } from './parts';

/*
| F11 — the company's own page (b2b.md §4.5, amendment 14; the design's company screen).
|
| Two columns: the status box and, under it, the form or what was sent; beside them, the
| application's lifecycle and nothing else (amendment 16(e)) — hidden while the company is suspended.
|
| **Before the first send there is no company, only a draft** (§1.1), and the page shows the draft
| alone — not sent yet, what is still missing, Continue or Discard. Once there is a company, what it
| sees follows its status: under review, the application it sent; approved, its details, its
| address and Change company details; rejected, why, and Apply again; suspended, why, and nothing
| to change. Every application it sent is listed under that, newest first, with its number.
|
| On Geist's parts (frontend.md 1.10): the status box is a Note coloured by the status, the
| company's details and its bank account are Geist's Description (an em dash where a value is not
| given), and a button that posts is `loading` until the page answers, so it cannot be pressed twice.
|
| **A company per store** (b2b.md amendments 18-20; owner, 2026-10-02): the page is about the store
| being browsed, and says which. An account with a company in another store but none here applies
| here, with the name and the type carried over and everything else this store's own; its
| companies in other stores are listed under the page, each with its status.
*/

export default function Company(page: CompanyPage) {
    const t = useTranslator();
    const locale = useLocale();
    const suspended = page.company?.status === 'SUSPENDED';
    const store = locale === 'ar' ? page.storeNameAr : page.storeNameEn;

    return (
        <AccountLayout title={t('b2b::company.title')} subtitle={t('b2b::company.subtitle_in_store', { store })} page="b2b.company">
            <div className={['grid gap-6', suspended ? '' : 'lg:grid-cols-[minmax(0,1fr)_18rem]'].join(' ')}>
                <div className="grid content-start gap-6">
                    <FormError />
                    {page.company === null ? <BeforeACompany page={page} /> : <WithACompany page={page} company={page.company} />}
                    {/* Under review, the application waiting is shown open above; the list below is the ones before it. */}
                    <CompanyHistory
                        history={page.company?.status === 'PENDING' ? page.history.slice(1) : page.history}
                        documentTypes={page.documentTypes}
                    />
                    <Elsewhere page={page} />
                </div>

                {suspended ? null : <CompanySide page={page} />}
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
                {t('b2b::company.status.new.body')}
                {page.stage === 'EMAIL_NOT_CONFIRMED' ? (
                    <p className="mt-2 text-warn" data-test="confirm-email-first">
                        {t('b2b::company.status.confirm_email')}
                    </p>
                ) : null}
                {page.draft !== null ? <Missing page={page} draft={page.draft} /> : null}
            </StatusBox>

            {page.draft === null && page.prefill !== null ? <CarriedOver prefill={page.prefill} /> : null}

            {page.draft === null ? (
                // A company elsewhere applies here, by name (owner, 2026-10-02: "Apply in this store").
                <StartButton label={t(page.prefill === null ? 'b2b::company.start' : 'b2b::company.apply_here')} />
            ) : (
                <CompanyForm page={page} draft={page.draft} lastSent={null} changing={false} />
            )}
        </>
    );
}

/** What the new draft starts with, and what it does not (owner, 2026-10-02; amendment 19). */
function CarriedOver({ prefill }: { prefill: NonNullable<CompanyPage['prefill']> }) {
    const t = useTranslator();
    const locale = useLocale();

    return (
        <Note label={t('b2b::company.prefill_label')} data-test="carried-over">
            {t('b2b::company.prefill', { store: locale === 'ar' ? prefill.fromStoreNameAr : prefill.fromStoreNameEn })}
        </Note>
    );
}

const ELSEWHERE_BADGE: Record<string, BadgeVariant> = {
    APPROVED: 'green-subtle',
    PENDING: 'amber-subtle',
    REJECTED: 'red-subtle',
    SUSPENDED: 'red-subtle',
};

/** The account's companies in the other stores: each store approves its own (amendment 18). */
function Elsewhere({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const locale = useLocale();

    if (page.elsewhere.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-3" data-test="elsewhere">
            <h2 className="text-heading-16 text-ink">{t('b2b::company.elsewhere')}</h2>
            <EntityList>
                {page.elsewhere.map((other) => (
                    <Entity
                        key={other.storeId}
                        data-test={`elsewhere-${other.storeId}`}
                        title={other.name}
                        description={locale === 'ar' ? other.storeNameAr : other.storeNameEn}
                        actions={
                            <Badge variant={ELSEWHERE_BADGE[other.status] ?? 'gray-subtle'}>
                                {t(`b2b::company.status.${other.status.toLowerCase()}.title`)}
                            </Badge>
                        }
                    />
                ))}
            </EntityList>
        </section>
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
                        {t('b2b::company.status.pending.body')}
                    </StatusBox>
                    {lastSent !== null ? (
                        <Card title={t('b2b::company.section.sent')} test="sent">
                            <SentApplication application={lastSent} previous={page.history[1] ?? null} documentTypes={page.documentTypes} open />
                        </Card>
                    ) : null}
                    <CompanyAddress page={page} company={company} />
                </>
            );

        case 'APPROVED':
            return (
                <>
                    <StatusBox tone="good" title={t('b2b::company.status.approved.title')}>
                        {t('b2b::company.status.approved.body')}
                    </StatusBox>
                    <CompanyCard company={company} reference={lastApproved(page.history)?.reference ?? null} />
                    <BankAccount page={page} />
                    {page.draft === null ? (
                        <>
                            <CompanyAddress page={page} company={company} />
                            <StartButton label={t('b2b::company.change')} type="secondary" test="change" />
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
                        <RejectedWhy company={company} history={page.history} />
                    </StatusBox>
                    {page.draft === null ? (
                        <>
                            <CompanyAddress page={page} company={company} />
                            <StartButton label={t('b2b::company.apply_again')} test="apply-again" />
                        </>
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
                        <span data-test="status-reason">{t('b2b::company.status.suspended.body', { reason: company.statusReason ?? '' })}</span>
                    </StatusBox>
                    <CompanyCard company={company} reference={null} />
                    {page.draft !== null ? <FrozenDraft /> : null}
                </>
            );
    }
}

/** What is still missing before a first send can go (§4.5): the list beside Send, without the fields' own states. */
function Missing({ page, draft }: { page: CompanyPage; draft: CompanyDraftData }) {
    const t = useTranslator();
    const items = missingItems(page, draft, null, t);

    return items.length === 0 ? null : (
        <p className="mt-2" data-test="missing">
            {t('b2b::company.missing', { items: items.join(t('b2b::company.separator')) })}
        </p>
    );
}

/** The company as it stands: what it sent last, read-only, as Geist's Description. */
function CompanyCard({ company, reference }: { company: CompanyStatusData; reference: string | null }) {
    const t = useTranslator();
    const locale = useLocale();
    const details = company.details;

    return (
        <Card title={t('b2b::company.section.company')} test="company">
            <Description
                items={[
                    ...(reference !== null ? [{ title: t('b2b::company.reference'), content: <Figure>{reference}</Figure> }] : []),
                    { title: t('b2b::company.field.name'), content: details.name },
                    { title: t('b2b::company.field.company_type'), content: typeOf(details, locale) },
                    { title: t('b2b::company.field.cr_number'), content: figureOr(details.crNumber) },
                    { title: t('b2b::company.field.tax_number'), content: figureOr(details.taxNumber) },
                    { title: t('b2b::company.field.address'), content: writtenOr(details.address) },
                ]}
            />
        </Card>
    );
}

/**
 * The company's address alone, picked from the account's saved addresses and saved the moment it is
 * picked, without review (§1.1, amendments 15(c) and 16(f)).
 */
function CompanyAddress({ page, company }: { page: CompanyPage; company: CompanyStatusData }) {
    const t = useTranslator();
    const link = useLink();
    const locale = useLocale();
    const [picking, setPicking] = useState<string | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const look: Look = picking !== null ? 'saving' : refusal !== null ? 'refused' : company.details.address !== null ? 'saved' : 'idle';

    return (
        <Card title={t('b2b::company.section.address')} hint={t('b2b::company.address_hint')} test="address">
            <AddressPicker
                addresses={page.savedAddresses}
                pickedId={company.details.addressId}
                pending={picking}
                kept={company.details.address}
                look={look}
                message={refusal}
                locale={locale}
                onPick={(addressId) => {
                    setPicking(addressId);
                    setRefusal(null);
                    router.post(
                        link('storefront.company.address'),
                        { address_id: addressId },
                        {
                            preserveScroll: true,
                            preserveState: true,
                            onError: (errors) => setRefusal(errors.address ?? errors.form ?? Object.values(errors)[0] ?? null),
                            onFinish: () => setPicking(null),
                        },
                    );
                }}
            />
        </Card>
    );
}

/**
 * Where an approved company transfers its payment (§2.3, amendment 12(b)), in the main column
 * (amendment 16(e)): the IBAN, the bank and the holder while bank transfer is on; that it is
 * temporarily unavailable while the store has not filled in all three (amendment 13(c)).
 */
function BankAccount({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const [copied, setCopied] = useState(false);
    const account = page.bankAccount;

    return (
        <Card title={t('b2b::company.payment.title')} test="payment">
            {account === null ? (
                <p className="text-copy-14 text-ink" data-test="bank-transfer-off">
                    {t('b2b::company.payment.off')}
                </p>
            ) : (
                <div className="grid gap-3" data-test="bank-account">
                    <p className="text-copy-14 text-ink">{t('b2b::company.payment.approved')}</p>
                    <Description
                        columns={1}
                        items={[
                            {
                                title: t('b2b::company.payment.iban'),
                                content: (
                                    <span className="flex flex-wrap items-center gap-2">
                                        <Figure>{account.iban}</Figure>
                                        <Button
                                            type="secondary"
                                            size="small"
                                            data-test="copy-iban"
                                            onClick={() => {
                                                void navigator.clipboard?.writeText(account.iban).then(() => setCopied(true));
                                            }}
                                        >
                                            {copied ? t('b2b::company.payment.copied') : t('b2b::company.payment.copy')}
                                        </Button>
                                    </span>
                                ),
                            },
                            { title: t('b2b::company.payment.bank'), content: account.bank },
                            { title: t('b2b::company.payment.holder'), content: account.holder },
                        ]}
                    />
                </div>
            )}
        </Card>
    );
}

/** Starts a draft (or a change, or a new application); `loading` until the page comes back with it. */
function StartButton({ label, type = 'default', test = 'start' }: { label: string; type?: 'default' | 'secondary'; test?: string }) {
    const link = useLink();
    const [starting, setStarting] = useState(false);

    return (
        <div>
            <Button
                type={type}
                data-test={test}
                loading={starting}
                onClick={() => {
                    setStarting(true);
                    router.post(link('storefront.company.start'), {}, { preserveScroll: true, onFinish: () => setStarting(false) });
                }}
            >
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
            <p className="text-copy-14 text-ink">{t('b2b::company.suspended_draft')}</p>
            <div>
                <Button type="secondary" data-test="discard" onClick={() => setConfirming(true)}>
                    {t('b2b::company.discard')}
                </Button>
            </div>
            <DiscardModal open={confirming} onOpenChange={setConfirming} />
        </Card>
    );
}

/**
 * Why it was not approved: the last rejection's own reason (amendment 15(b)). The company's reason
 * is what it was told last, which a reinstatement since has replaced — shown then on its own line,
 * never as the reason it was rejected. The reason runs on from the status box's label; the
 * reinstatement is a paragraph of its own.
 */
function RejectedWhy({ company, history }: { company: CompanyStatusData; history: CompanyApplicationData[] }) {
    const t = useTranslator();
    const rejection = history.find((application) => application.state === 'REJECTED');
    const reason = rejection?.decisionReason ?? company.statusReason ?? '';
    const since = company.statusReason !== null && company.statusReason !== reason ? company.statusReason : null;

    return (
        <>
            <span data-test="status-reason">{t('b2b::company.status.rejected.body', { reason })}</span>
            {since !== null ? (
                <p className="mt-2" data-test="reinstated">
                    {t('b2b::company.status.reinstated', { reason: since })}
                </p>
            ) : null}
        </>
    );
}

function lastApproved(history: CompanyApplicationData[]): CompanyApplicationData | undefined {
    return history.find((application) => application.state === 'APPROVED');
}
