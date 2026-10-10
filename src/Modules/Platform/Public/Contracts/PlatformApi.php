<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use DateTimeImmutable;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\ModuleDeleteDto;
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
     * Any store, **on or off**: a store is named in history as it was, so an id taken from an order,
     * an address or an audit entry always finds its store. Look at `isActive` before treating it as
     * a place anybody may work or shop in (platform.md §1.6).
     *
     * Served from the cache: once warm, it reads only the cache table (two tiny queries), never the
     * store tables.
     */
    public function store(StoreId $id): ?StoreDto;

    /**
     * An **on** store by its code; an off store's code answers null, exactly as an unknown code does
     * (platform.md §1.6). A code arrives from outside — a URL, a cookie, a form — and an off store is
     * as if it were never there.
     */
    public function storeByCode(string $code): ?StoreDto;

    /**
     * The stores that are **on**, ordered by position: the ones a chooser, a switcher or a store
     * picker may offer (platform.md §1.6).
     *
     * @return list<StoreDto>
     */
    public function stores(): array;

    /**
     * Every store, **on and off**, ordered by position — for work that must reach a store before it
     * opens (a new store's starting data) and for history. Never for a list a person chooses from,
     * with one exception: a Super Admin's lists in the panel (every store filter, Home's switcher,
     * View Store), which offer the off stores marked Off, to prepare one before it opens
     * (platform.md §1.6, §9.10 #4; owner, 2026-10-06).
     *
     * @return list<StoreDto>
     */
    public function allStores(): array;

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

    /**
     * A module deletes a file it holds for its own use (B2B step 3, amendments 4 and 5) — the mirror
     * of uploadMediaFor. Platform checks the permission that module names for the change, in the
     * scope it names — not `platform.media.delete`, which a customer replacing a company document
     * should never need to hold. Staff deletion of media is unchanged.
     *
     * - **Private files only.** A public image is refused.
     * - **Refused while any use remains**, the calling module's own included: the module removes
     *   its reference first. Another module's use is never detached on the caller's behalf.
     * - Otherwise it is deleted as any other media is: in a savepoint of the caller's transaction,
     *   with the files removed and MediaDeleted sent only once the outermost transaction commits,
     *   and audited with the module and the permission, as a module's upload is.
     *
     * @throws DomainError when the permission does not belong to that module or is not held, when
     *                     nobody declared it, when the file is public, still used, or does not exist
     */
    public function deleteMediaFor(ModuleDeleteDto $delete): void;

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
     * As mediaUrls(), for a page of media at once, **in one query**: a screen showing twenty photos
     * reads them together, not one at a time (catalog.md §2.4; owner, 2026-10-07). The same rules —
     * a public image's variants once they are ready, a private file's expiring link — and the same
     * caution: the calling module checks its viewer may see a private file first.
     *
     * @param  list<string>  $mediaIds
     * @return array<string, MediaUrlsDto> keyed by the media id, lower-cased; an id that is not a
     *                                     ULID, or names no media, is left out
     */
    public function mediaUrlsOf(array $mediaIds): array;

    /**
     * As media(), for a page of media at once, **in one query**: a screen showing a gallery reads each
     * photo's state together (catalog.md §2.4, S9; owner, 2026-10-07, #10).
     *
     * @param  list<string>  $mediaIds
     * @return array<string, MediaDto> keyed by the media id, lower-cased; an id that is not a ULID, or
     *                                 names no media, is left out
     */
    public function mediaOf(array $mediaIds): array;

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
