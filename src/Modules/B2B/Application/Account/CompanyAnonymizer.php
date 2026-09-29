<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Account;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Application\Audit\CompanyAccountAudit;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * What anonymizing an account reaches in B2B (b2b.md §1.1, §6, amendment 12(a)), once Access has
 * emptied the account:
 *
 * - **an unsent draft is deleted whole**, with the files only it holds, as discarding it would —
 *   and audited as a discard;
 * - **every application the company sent** keeps its state, type, dates, decision, flags and
 *   requests, and gives up its four personal values, its note, its answers and its papers
 *   (Application::anonymize);
 * - **the company** keeps its row, type, status and reason, and gives up the same four values
 *   (Company::anonymize), audited once as `b2b.company.anonymized`;
 * - every file let go of is deleted through Platform, after the rows that held it are written.
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
        private AccessApi $access,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    public function anonymize(string $customerId): void
    {
        $this->db->transaction(function () use ($customerId): void {
            $this->applications->lockAccount($customerId);
            $company = $this->companies->forCustomerLocked($customerId);
            $open = $this->applications->openFor($customerId);
            $draft = $open?->state() === ApplicationState::Draft ? $open : null;
            $released = [];

            if ($draft !== null) {
                $released = self::heldBy($draft);
                // Its rows go first — Platform deletes a file only once nothing holds it.
                $this->applications->delete($draft->id());
                // Access keeps the emptied account's row, and with it the home store.
                $homeStoreId = $company?->homeStoreId() ?? $this->access->customer($customerId)->homeStoreId
                    ?? throw new LogicException('Access no longer knows the account it anonymized.');
                $this->platform->recordAudit(CompanyAccountAudit::discarded($draft, $homeStoreId));
            }

            if ($company !== null) {
                $anonymized = 0;

                foreach ($this->applications->historyOf($company->id()) as $sent) {
                    $released = [...$released, ...$sent->anonymize()];

                    if ($sent->pullChanges() !== []) {
                        $this->applications->update($sent);
                        $anonymized++;
                    }
                }

                $company->anonymize();
                $fields = $company->pullChanges();

                if ($fields !== [] || $anonymized > 0) {
                    $this->companies->update($company);
                    $this->platform->recordAudit(CompanyAccountAudit::anonymized($company, $fields, $anonymized, count(array_unique($released))));
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
