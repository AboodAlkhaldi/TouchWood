import { lazy, Suspense, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { Note } from '@/components/Note';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { CompanyPage, CompanyStatusData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { SentApplication } from './CompanyHistory';
import { ApprovedStatus, CompanyCard, CompanyShell, NewStatus, Reason, RejectedWhy } from './CompanyShell';
import { AddressPicker, type Look } from './fields';
import { Card, MARK, StatusBox, useLocale } from './parts';

// The frozen draft's confirmation loads when first asked, not with the page (frontend.md §5).
const DiscardModal = lazy(() => import('./dialogs').then((module) => ({ default: module.DiscardModal })));

/*
| F11 - the company's own page (b2b.md §4.5, amendment 14; the design's company screen), as it reads
| (amendment 35): the form's states have a page of their own, Edit.tsx, and this one never carries
| the form (owner, 2026-11-10: "ok split them into two pages"). The address is the same for both;
| the server picks which is drawn.
|
| **Before the first send there is no company, only a draft** (§1.1): until one is started, this
| page says it is not sent yet and offers to start it. Once there is a company, what it sees follows
| its status: under review, the application it sent; approved, its details, its address and Change
| Company Details; rejected, why, and Apply Again; suspended, why, and nothing to change - an
| unsent draft may only go. Starting a draft opens the form's page. Every application it sent is
| listed under that, newest first (CompanyShell).
|
| A button that posts is `loading` until the page answers, so it cannot be pressed twice.
|
| **A company per store** (b2b.md amendments 18-20; owner, 2026-10-02): an account with a company in
| another store but none here applies here, with the name and the type carried over and everything
| else this store's own.
*/

export default function Company(page: CompanyPage) {
    return <CompanyShell page={page}>{page.company === null ? <BeforeACompany page={page} /> : <WithACompany page={page} company={page.company} />}</CompanyShell>;
}

/** Nothing sent yet, and no draft: the way to start one (§4.3). */
function BeforeACompany({ page }: { page: CompanyPage }) {
    const t = useTranslator();

    return (
        <>
            <NewStatus page={page} />

            {page.draft === null && page.prefill !== null ? <CarriedOver prefill={page.prefill} /> : null}

            {page.draft === null ? (
                // A company elsewhere applies here, by name (owner, 2026-10-02: "Apply in this store").
                <StartButton label={page.prefill === null ? t('b2b::company.start') : t('b2b::company.apply_here')} />
            ) : null}
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
            // With a draft open, its change is the form's page (Edit.tsx).
            return (
                <>
                    <ApprovedStatus page={page} company={company} />
                    {page.draft === null ? (
                        <>
                            <CompanyAddress page={page} company={company} />
                            <StartButton label={t('b2b::company.change')} outline test="change" />
                        </>
                    ) : null}
                </>
            );

        case 'REJECTED':
            // With a draft open, applying again is the form's page (Edit.tsx).
            return (
                <>
                    <RejectedWhy company={company} history={page.history} />
                    {page.draft === null ? (
                        <>
                            <CompanyAddress page={page} company={company} />
                            <StartButton label={t('b2b::company.apply_again')} test="apply-again" />
                        </>
                    ) : null}
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
 * Starts a draft (or a change, or a new application); `loading` until the page comes back with it -
 * the form's page, which the server draws once a draft is open.
 */
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
    // Loaded on the first press, then kept, so it closes with its animation (dialogs.tsx).
    const [asked, setAsked] = useState(false);

    return (
        <Card test="frozen-draft">
            <p className="text-copy-14 text-ink">{t('b2b::company.suspended_draft')}</p>
            <div>
                <ActionButton
                    variant="outline"
                    data-test="discard"
                    onClick={() => {
                        setAsked(true);
                        setConfirming(true);
                    }}
                >
                    {t('b2b::company.discard_open')}
                </ActionButton>
            </div>
            {asked ? (
                <Suspense fallback={null}>
                    <DiscardModal open={confirming} onOpenChange={setConfirming} />
                </Suspense>
            ) : null}
        </Card>
    );
}
