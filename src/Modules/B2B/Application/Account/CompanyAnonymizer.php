<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\B2B\Application\Audit\CompanyAccountAudit;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * What anonymizing an account reaches in B2B (b2b.md §1.1, §6, amendments 12(a), 13(a)), once Access
 * has emptied the account (AnonymizeCompany, from the queue):
 *
 * - **an unsent draft is deleted whole**, with the files only it holds, as discarding it would —
 *   and audited as a discard; an application still waiting is sent, so it is kept like the others;
 * - **every application the company sent** keeps its state, type, dates, decision, flags and
 *   requests, and gives up its four personal values and any "Other" words, its note, its answers
 *   and its papers (Application::anonymize);
 * - **the company** keeps its row, type, status and reason, and gives up the same values
 *   (Company::anonymize), audited once as `b2b.company.anonymized`;
 * - every file let go of is deleted through Platform, after the rows that held it are written.
 *
 * **In every store** the account applied in (amendment 20(j)): each store's draft, and each store's
 * company with its applications.
 *
 * One transaction, under the account's lock (the same order as every B2B use case: the account,
 * then the company row). **A second time changes nothing and records nothing.** An account with
 * neither a company nor a draft — an individual — is left alone.
 */
final readonly class CompanyAnonymizer
{
    public function __construct(
        private CompanyRepository $companies,
        private ApplicationRepository $applications,
        private ApplicationFiles $files,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    public function anonymize(string $customerId): void
    {
        $this->db->transaction(function () use ($customerId): void {
            $this->applications->lockAccount($customerId);
            /** @var array<string, list<string>> $draftFiles store id => the files its draft held */
            $draftFiles = [];

            // Every store's open draft (amendment 20(j)): each is deleted whole.
            foreach ($this->applications->openAllFor($customerId) as $open) {
                if ($open->state() !== ApplicationState::Draft) {
                    continue;
                }

                $draftFiles[$open->storeId()] = [...($draftFiles[$open->storeId()] ?? []), ...self::heldBy($open)];
                // Its rows go first — Platform deletes a file only once nothing holds it.
                $this->applications->delete($open->id());
                $this->platform->recordAudit(CompanyAccountAudit::discarded($open, $open->storeId()));
            }

            $released = array_merge([], ...array_values($draftFiles));

            // And the company in every store it applied in (amendment 20(j)), each read again under
            // its lock; each audit entry counts the files of its own store.
            foreach ($this->companies->allForCustomer($customerId) as $found) {
                $company = $this->companies->byId($found->id()) ?? throw new LogicException("The company {$found->id()} went while its account was locked.");
                $anonymized = 0;
                $theirs = $draftFiles[strtolower($company->homeStoreId())] ?? [];

                foreach ($this->applications->historyOf($company->id()) as $sent) {
                    $files = $sent->anonymize();
                    $theirs = [...$theirs, ...$files];
                    $released = [...$released, ...$files];

                    if ($sent->pullChanges() !== []) {
                        $this->applications->update($sent);
                        $anonymized++;
                    }
                }

                $company->anonymize();
                $fields = $company->pullChanges();

                if ($fields !== [] || $anonymized > 0) {
                    $this->companies->update($company);
                    $this->platform->recordAudit(CompanyAccountAudit::anonymized($company, $fields, $anonymized, count(array_unique($theirs))));
                }
            }

            $this->files->release($released);
        }, 3);
    }

    /**
     * @return list<string> every file the application holds: its papers and its answers' files
     */
    private static function heldBy(Application $application): array
    {
        $held = [];

        foreach ($application->documents() as $document) {
            $held[] = $document->mediaId;
        }

        foreach ($application->answers() as $answer) {
            if ($answer->mediaId !== null) {
                $held[] = $answer->mediaId;
            }
        }

        return $held;
    }
}
