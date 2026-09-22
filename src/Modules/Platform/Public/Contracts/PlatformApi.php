<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use DateTimeImmutable;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Dto\SettingValueDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Actor;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Platform (Platform spec §2.1).
 */
interface PlatformApi
{
    /**
     * Served from the cache: once warm, it reads only the cache table (two tiny queries), never the
     * store tables.
     */
    public function store(StoreId $id): ?StoreDto;

    public function storeByCode(string $code): ?StoreDto;

    /**
     * @return list<StoreDto> ordered by position
     */
    public function stores(): array;

    public function currency(string $code): ?CurrencyDto;

    /**
     * The stored value, or the declared default. Served from cache.
     *
     * @param  StoreId|null  $store  required for a per-store setting, forbidden for a global one
     *
     * @throws DomainError for an undeclared key or the wrong scope
     */
    public function setting(string $key, ?StoreId $store = null): SettingValueDto;

    /**
     * A module uploads a file for its own use (stage 2b, P1). Platform checks the permission that
     * module names for the change — not `platform.media.upload`, which a staff member setting their
     * own picture, or a customer sending a company document, should never need to hold.
     *
     * The file is stored, deduplicated, limited, audited and deletable exactly as any other media.
     *
     * @return string the media id — of the existing media when the same public image was uploaded before
     *
     * @throws DomainError when the permission does not belong to that module, when nobody declared
     *                     it, or when the file is not one the settings allow
     */
    public function uploadMediaFor(ModuleUploadDto $upload): string;

    public function media(string $mediaId): ?MediaDto;

    /**
     * For a public image, the CDN URL of every variant once they are ready; its original is never
     * served. For a private file, an expiring link (30 minutes) to the original, which never goes
     * through the CDN.
     *
     * Platform does not know who may see a private file: the calling module checks that its
     * viewer may see it (e.g. B2B for a company's documents) before asking for the link.
     */
    public function mediaUrls(string $mediaId): ?MediaUrlsDto;

    /**
     * Call inside the transaction of the change being audited, after checking permission. Platform
     * records the actor, the source (web, integration, console or job) and the date itself.
     */
    public function recordAudit(AuditEntryDto $entry): void;

    /**
     * For the data migration only: history from the old system keeps its real date and actor, and
     * is marked as imported. Runs as the system, inside a transaction; the date must be past.
     */
    public function recordImportedAudit(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void;
}
