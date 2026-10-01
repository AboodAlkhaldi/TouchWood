<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\OpenMyApplicationFile;

use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Domain\Exception\ApplicationFileNotFound;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * **The company may open its own files** (b2b.md §1.4, amendment 5): a link that lasts 30 minutes, to
 * a file one of the account's own applications holds — a paper or a file answer, sent or still in
 * the draft — and nothing else.
 *
 * Platform does not know who may see a private file: B2B asks first, and only then asks Platform for
 * the link. Any other file is ApplicationFileNotFound, the same whether it exists or not (amendment
 * 9(c)). Not audited (amendment 4 names the three company actions that are).
 */
final readonly class OpenMyApplicationFileHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private ApplicationRepository $applications,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws NotACompanyAccount|ApplicationFileNotFound
     */
    public function handle(OpenMyApplicationFile $query): FileLink
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->account->get(self::PERMISSION)->id;

        if (! $this->applications->accountHolds($customerId, $query->mediaId)) {
            throw new ApplicationFileNotFound;
        }

        $urls = $this->platform->mediaUrls($query->mediaId);

        // Held but gone from Platform would be a broken record; the answer stays the same.
        if ($urls?->original === null || $urls->expiresAt === null) {
            throw new ApplicationFileNotFound;
        }

        return new FileLink($urls->original, $urls->expiresAt);
    }
}
