import type { CompanyDraftData, CompanyPage } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { CompanyForm } from './CompanyForm';
import { ApprovedStatus, CompanyShell, NewStatus, RejectedWhy } from './CompanyShell';

/*
| F11's form (b2b.md §4.5, amendments 14 and 35): the company page while a draft is open that may
| change, at the page's own address - the server draws this page then, and the view page
| (Company.tsx) otherwise (owner, 2026-11-10: "ok split them into two pages").
|
| Three states: **before the first send**, the draft alone - not sent yet, what is still missing
| (beside Send), Continue or Discard; **an approved company changing its details**, its details and
| bank account as they stand above the form, which warns before it sends (14(e)); **a rejected one
| applying again**, why above the form, which marks what that rejection asked to change. Sending or
| discarding comes back to the view page. The frame, the applications sent and the lifecycle are the
| same as the view page's (CompanyShell).
*/

export default function Edit(page: CompanyPage) {
    return <CompanyShell page={page}>{page.draft === null ? null : <Draft page={page} draft={page.draft} />}</CompanyShell>;
}

function Draft({ page, draft }: { page: CompanyPage; draft: CompanyDraftData }) {
    const company = page.company;
    const lastSent = page.history[0] ?? null;

    if (company === null) {
        return (
            <>
                <NewStatus page={page} />
                <CompanyForm page={page} draft={draft} lastSent={null} changing={false} />
            </>
        );
    }

    switch (company.status) {
        case 'APPROVED':
            return (
                <>
                    <ApprovedStatus page={page} company={company} />
                    <CompanyForm page={page} draft={draft} lastSent={null} changing />
                </>
            );

        case 'REJECTED':
            return (
                <>
                    <RejectedWhy company={company} history={page.history} />
                    <CompanyForm page={page} draft={draft} lastSent={lastSent?.state === 'REJECTED' ? lastSent : null} changing={false} />
                </>
            );

        default:
            // Under review or suspended there is nothing to change: the server draws the view page instead.
            return null;
    }
}
