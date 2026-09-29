<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DownloadCompanyDocument;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Audit\StaffCompanyAudit;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\OpenMyApplicationFile\FileLink;
use Modules\B2B\Application\Staff\StaffCompanyAction;
use Modules\B2B\Domain\Exception\ApplicationFileNotFound;
use Modules\B2B\Domain\Exception\CompanyNotFound;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **`DownloadCompanyDocument`** (b2b.md §1.4, §3.2): a signed link, for 30 minutes, to a paper or a
 * file answer of one of the company's **sent** applications — never a draft's, which nobody reviews
 * until it is sent. Any other file is `ApplicationFileNotFound`, the same whether or not it exists.
 *
 * **Each opening is audited** (amendment 10(f)): who, which company, which application, and which
 * paper type or request — never the file's id, so the log tells a reader without the private-files
 * permission nothing about which file exists (amendment 8(c)). The link is only handed back once
 * the entry is written.
 */
final readonly class DownloadCompanyDocumentHandler
{
    public const string PERMISSION = B2BPermissions::COMPANY_DOCUMENT_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StaffCompanyAction $action,
        private ApplicationRepository $applications,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws ApplicationFileNotFound|CompanyNotFound|Unauthorized
     */
    public function handle(DownloadCompanyDocument $command): FileLink
    {
        [$scope, $company] = $this->action->about(self::PERMISSION, $command->companyId);
        $this->authorizer->authorize(self::PERMISSION, $scope);
        $mediaId = strtolower(trim($command->mediaId));

        [$applicationId, $documentTypeId, $requestId] = $this->find($company->id(), $mediaId) ?? throw new ApplicationFileNotFound;
        $urls = $this->platform->mediaUrls($mediaId);

        // Held but gone from Platform would be a broken record; the answer stays the same.
        if ($urls?->original === null || $urls->expiresAt === null) {
            throw new ApplicationFileNotFound;
        }

        $this->db->transaction(function () use ($company, $applicationId, $documentTypeId, $requestId): void {
            $this->platform->recordAudit(StaffCompanyAudit::documentOpened($company, $applicationId, $documentTypeId, $requestId));
        }, 3);

        return new FileLink($urls->original, $urls->expiresAt);
    }

    /**
     * Which sent application holds the file, and under which paper type or request.
     *
     * @return array{0: string, 1: ?string, 2: ?string}|null
     */
    private function find(string $companyId, string $mediaId): ?array
    {
        foreach ($this->applications->historyOf($companyId) as $sent) {
            foreach ($sent->documents() as $typeId => $document) {
                if ($document->mediaId === $mediaId) {
                    return [$sent->id(), $typeId, null];
                }
            }

            foreach ($sent->answers() as $requestId => $answer) {
                if ($answer->mediaId === $mediaId) {
                    return [$sent->id(), null, $requestId];
                }
            }
        }

        return null;
    }
}
