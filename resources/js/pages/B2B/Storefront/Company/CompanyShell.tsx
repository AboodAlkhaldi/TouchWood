import type { ReactNode } from 'react';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { CopyButton } from '@/components/geist-only/CopyButton';
import { Description } from '@/components/geist-only/Description';
import { Badge } from '@/components/ui/badge';
import { Field, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Item, ItemActions, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { AccountLayout } from '@/layouts/AccountLayout';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { CompanyApplicationData, CompanyPage, CompanyStatusData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { companyStatusTone } from '../../status';
import { CompanyHistory } from './CompanyHistory';
import { CompanySide } from './CompanySide';
import { Card, Figure, figureOr, MARK, Phrase, StatusBox, typeOf, useLocale, writtenOr } from './parts';

/*
| What the company's two pages share (b2b.md §4.5, amendments 14 and 35): the frame - two columns,
| the status box and what follows it, the applications sent and the companies elsewhere under it,
| the lifecycle beside it (amendment 16(e)), hidden while the company is suspended - and the status
| boxes both draw: not sent yet, approved with its details and bank account, not approved and why.
| Their confirmations load on first press (dialogs.tsx).
|
| The view page (Company.tsx) shows everything that only reads; the form's page (Edit.tsx) shows an
| open draft that may change. Which one is drawn is the server's choice (MyCompanyController::show).
|
| On shadcn's parts with Geist's rules (frontend.md §1.11): the status box is Geist's Note coloured
| by the status, one sentence, and anything else - confirm your email, a reinstatement - its own
| Note (Geist: one Note per concept); the company's details are Geist's Description (an em dash
| where a value is not given); the IBAN sits in a read-only field with Geist's Copy Button.
|
| **A company per store** (b2b.md amendments 18-20; owner, 2026-10-02): the page is about the store
| being browsed, and says which; the account's companies in other stores are listed under it, each
| with its status.
*/

export function CompanyShell({ page, children }: { page: CompanyPage; children: ReactNode }) {
    const t = useTranslator();
    const locale = useLocale();
    const suspended = page.company?.status === 'SUSPENDED';
    const store = locale === 'ar' ? page.storeNameAr : page.storeNameEn;

    return (
        <AccountLayout title={t('b2b::company.title')} subtitle={t('b2b::company.subtitle_in_store', { store })} page="b2b.company">
            <div className={['grid gap-6', suspended ? '' : 'lg:grid-cols-[minmax(0,1fr)_18rem]'].join(' ')}>
                <div className="grid content-start gap-6">
                    <FormError />
                    {children}
                    {/* Under review, the application waiting is shown open above; the list below is the ones before it. */}
                    <CompanyHistory history={page.company?.status === 'PENDING' ? page.history.slice(1) : page.history} documentTypes={page.documentTypes} />
                    <Elsewhere page={page} />
                </div>

                {suspended ? null : <CompanySide page={page} />}
            </div>
        </AccountLayout>
    );
}

/** Before a company: not sent yet - and, before the email is confirmed, that it must be (§4.3). */
export function NewStatus({ page }: { page: CompanyPage }) {
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
        </>
    );
}

/** Approved: the status box, the company as it stands and where it transfers its payment. */
export function ApprovedStatus({ page, company }: { page: CompanyPage; company: CompanyStatusData }) {
    const t = useTranslator();

    return (
        <>
            <StatusBox tone="good" title={t('b2b::company.status.approved.title')}>
                {t('b2b::company.status.approved.body')}
            </StatusBox>
            <CompanyCard company={company} reference={lastApproved(page.history)?.reference ?? null} />
            <BankAccount page={page} />
        </>
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

/** The company as it stands: what it sent last, read-only, as Geist's Description. */
export function CompanyCard({ company, reference }: { company: CompanyStatusData; reference: string | null }) {
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

/**
 * Why it was not approved: the last rejection's own reason (amendment 15(b)), in the status box. The
 * company's reason is what it was told last, which a reinstatement since has replaced - said then in
 * a Note of its own, never as the reason it was rejected.
 */
export function RejectedWhy({ company, history }: { company: CompanyStatusData; history: CompanyApplicationData[] }) {
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
export function Reason({ text, reason }: { text: string; reason: string }) {
    return <Phrase text={text} moment={<bdi>{reason}</bdi>} />;
}

function lastApproved(history: CompanyApplicationData[]): CompanyApplicationData | undefined {
    return history.find((application) => application.state === 'APPROVED');
}
