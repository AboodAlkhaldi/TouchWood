import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Description } from '@/components/geist-only/Description';
import { Badge } from '@/components/ui/badge';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyApplicationData, CompanyPage, CompanyStatusData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { companyStatusTone } from '../../status';
import { CompanyForm, DiscardModal } from './CompanyForm';
import { CompanyHistory, SentApplication } from './CompanyHistory';
import { CompanySide } from './CompanySide';
import { AddressPicker, type Look } from './fields';
import { Card, Figure, figureOr, MARK, Phrase, StatusBox, typeOf, useLocale, writtenOr } from './parts';

/*
| F11 - the company's own page (b2b.md §4.5, amendment 14; the design's company screen).
|
| Two columns: the status box and, under it, the form or what was sent; beside them, the
| application's lifecycle and nothing else (amendment 16(e)) - hidden while the company is suspended.
|
| **Before the first send there is no company, only a draft** (§1.1), and the page shows the draft
| alone - not sent yet, what is still missing (beside Send), Continue or Discard. Once there is a
| company, what it sees follows its status: under review, the application it sent; approved, its
| details, its address and Change Company Details; rejected, why, and Apply Again; suspended, why,
| and nothing to change. Every application it sent is listed under that, newest first.
|
| On shadcn's parts with Geist's rules (frontend.md §1.11): the status box is Geist's Note coloured
| by the status, one sentence, and anything else - confirm your email, a reinstatement - its own
| Note (Geist: one Note per concept); the company's details are Geist's Description (an em dash
| where a value is not given); the IBAN sits in a read-only field with Geist's Copy Button; a button
| that posts is `loading` until the page answers, so it cannot be pressed twice.
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
                    <CompanyHistory history={page.company?.status === 'PENDING' ? page.history.slice(1) : page.history} documentTypes={page.documentTypes} />
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
            </StatusBox>

            {page.stage === 'EMAIL_NOT_CONFIRMED' ? (
                <div data-test="confirm-email-first">
                    <Note variant="warning">{t('b2b::company.status.confirm_email')}</Note>
                </div>
            ) : null}

            {page.draft === null && page.prefill !== null ? <CarriedOver prefill={page.prefill} /> : null}

            {page.draft === null ? (
                // A company elsewhere applies here, by name (owner, 2026-10-02: "Apply in this store").
                <StartButton label={page.prefill === null ? t('b2b::company.start') : t('b2b::company.apply_here')} />
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
    const store = locale === 'ar' ? prefill.fromStoreNameAr : prefill.fromStoreNameEn;

    return (
        <Note label={t('b2b::company.prefill_label')} data-test="carried-over">
            {/* The type comes along only when this store's list has one of the same name (19(b)). */}
            {prefill.companyTypeId === null && prefill.companyTypeOther === null ? t('b2b::company.prefill_name_only', { store }) : t('b2b::company.prefill', { store })}
        </Note>
    );
}

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
            <ItemGroup className="material-base overflow-hidden">
                {page.elsewhere.map((other, index) => (
                    <div key={other.storeId} role="listitem">
                        {index === 0 ? null : <ItemSeparator className="my-0" />}
                        <Item size="sm" className="rounded-none px-4" data-test={`elsewhere-${other.storeId}`}>
                            <ItemContent className="min-w-0">
                                <ItemTitle className="text-label-14 text-ink">{other.name}</ItemTitle>
                                <ItemDescription className="text-copy-13 text-ink-muted">{locale === 'ar' ? other.storeNameAr : other.storeNameEn}</ItemDescription>
                            </ItemContent>
                            <ItemActions>
                                <Badge className={tone(companyStatusTone(other.status))}>{t(`b2b::company.status.${other.status.toLowerCase()}.title`)}</Badge>
                            </ItemActions>
                        </Item>
                    </div>
                ))}
            </ItemGroup>
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
                            <StartButton label={t('b2b::company.change')} outline test="change" />
                        </>
                    ) : (
                        <CompanyForm page={page} draft={page.draft} lastSent={null} changing />
                    )}
                </>
            );

        case 'REJECTED':
            return (
                <>
                    <RejectedWhy company={company} history={page.history} />
                    {page.draft === null ? (
                        <>
                            <CompanyAddress page={page} company={company} />
                            <StartButton label={t('b2b::company.apply_again')} test="apply-again" />
                        </>
                    ) : (
                        <CompanyForm page={page} draft={page.draft} lastSent={lastSent?.state === 'REJECTED' ? lastSent : null} changing={false} />
                    )}
                </>
            );

        default:
            // SUSPENDED: nothing changes until staff reinstate it (§1.1) - but an unsent draft may go.
            return (
                <>
                    <StatusBox tone="bad" title={t('b2b::company.status.suspended.title')}>
                        {/* One sentence, the reason running into what follows: its own ending stop goes. */}
                        <span data-test="status-reason">
                            <Reason text={t('b2b::company.status.suspended.body', { reason: MARK })} reason={(company.statusReason ?? '').replace(/[\s.;؛]+$/u, '')} />
                        </span>
                    </StatusBox>
                    <CompanyCard company={company} reference={null} />
                    {page.draft !== null ? <FrozenDraft /> : null}
                </>
            );
    }
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
                    { title: t('b2b::company.field.name'), content: writtenOr(details.name) },
                    { title: t('b2b::company.field.company_type'), content: writtenOr(typeOf(details, locale)) },
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
 * temporarily unavailable while the store has not filled in all three (amendment 13(c)). The IBAN
 * is a read-only field with Geist's Copy Button at its end (shadcn's input-group-button).
 */
function BankAccount({ page }: { page: CompanyPage }) {
    const t = useTranslator();
    const account = page.bankAccount;

    return (
        <Card title={t('b2b::company.payment.title')} test="payment">
            {account === null ? (
                <p className="text-copy-14 text-ink" data-test="bank-transfer-off">
                    {t('b2b::company.payment.off')}
                </p>
            ) : (
                <div className="grid gap-4" data-test="bank-account">
                    <p className="text-copy-14 text-ink">{t('b2b::company.payment.approved')}</p>
                    <Field>
                        <FieldLabel htmlFor="company-iban">{t('b2b::company.payment.iban')}</FieldLabel>
                        <InputGroup>
                            <InputGroupInput id="company-iban" readOnly dir="ltr" value={account.iban} className="tw-figure" data-test="iban" />
                            <InputGroupAddon align="inline-end">
                                <CopyButton text={account.iban} label={t('b2b::company.payment.copy')} />
                            </InputGroupAddon>
                        </InputGroup>
                    </Field>
                    <Description
                        items={[
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
function StartButton({ label, outline = false, test = 'start' }: { label: string; outline?: boolean; test?: string }) {
    const link = useLink();
    const [starting, setStarting] = useState(false);

    return (
        <div>
            <ActionButton
                variant={outline ? 'outline' : 'default'}
                data-test={test}
                loading={starting}
                onClick={() => {
                    setStarting(true);
                    router.post(link('storefront.company.start'), {}, { preserveScroll: true, onFinish: () => setStarting(false) });
                }}
            >
                {label}
            </ActionButton>
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
                <ActionButton variant="outline" data-test="discard" onClick={() => setConfirming(true)}>
                    {t('b2b::company.discard_open')}
                </ActionButton>
            </div>
            <DiscardModal open={confirming} onOpenChange={setConfirming} />
        </Card>
    );
}

/**
 * Why it was not approved: the last rejection's own reason (amendment 15(b)), in the status box. The
 * company's reason is what it was told last, which a reinstatement since has replaced - said then in
 * a Note of its own, never as the reason it was rejected.
 */
function RejectedWhy({ company, history }: { company: CompanyStatusData; history: CompanyApplicationData[] }) {
    const t = useTranslator();
    const rejection = history.find((application) => application.state === 'REJECTED');
    const reason = rejection?.decisionReason ?? company.statusReason ?? '';
    const since = company.statusReason !== null && company.statusReason !== reason ? company.statusReason : null;

    return (
        <>
            <StatusBox tone="bad" title={t('b2b::company.status.rejected.title')}>
                <span data-test="status-reason">
                    <Reason text={t('b2b::company.status.rejected.body', { reason: MARK })} reason={reason} />
                </span>
            </StatusBox>
            {since !== null ? (
                <div data-test="reinstated">
                    <Note variant="secondary">
                        <Reason text={t('b2b::company.status.reinstated', { reason: MARK })} reason={since} />
                    </Note>
                </div>
            ) : null}
        </>
    );
}

/**
 * A sentence with our team's own words inside it: kept whole in their own direction, so an English
 * reason in an Arabic sentence (or the other way round) keeps its full stop where it was written.
 */
function Reason({ text, reason }: { text: string; reason: string }) {
    return <Phrase text={text} moment={<bdi>{reason}</bdi>} />;
}

function lastApproved(history: CompanyApplicationData[]): CompanyApplicationData | undefined {
    return history.find((application) => application.state === 'APPROVED');
}
