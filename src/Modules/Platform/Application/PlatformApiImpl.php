<?php

declare(strict_types=1);

namespace Modules\Platform\Application;

use DateTimeImmutable;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Application\Query\NameOrder;
use Modules\Platform\Application\Query\PlaceReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AddressCountryDto;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\ModuleDeleteDto;
use Modules\Platform\Public\Dto\ModuleUploadDto;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\SettingValueDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Actor;
use Shared\Domain\ValueObject\StoreId;

final readonly class PlatformApiImpl implements PlatformApi
{
    public function __construct(
        private StoreDirectory $directory,
        private AuditLog $auditLog,
        private ReadSetting $readSetting,
        private MediaReader $mediaReader,
        private UploadMediaHandler $uploadMedia,
        private DeleteMediaHandler $deleteMedia,
        private PlaceReader $places,
    ) {}

    public function store(StoreId $id): ?StoreDto
    {
        return $this->directory->storeById($id->value);
    }

    public function storeByCode(string $code): ?StoreDto
    {
        $store = $this->directory->storeByCode($code);

        // An off store's code answers as an unknown one (platform.md §1.6).
        return $store !== null && $store->isActive ? $store : null;
    }

    public function stores(): array
    {
        return array_values(array_filter($this->directory->stores(), static fn (StoreDto $store): bool => $store->isActive));
    }

    public function allStores(): array
    {
        return $this->directory->stores();
    }

    public function currency(string $code): ?CurrencyDto
    {
        return $this->directory->currency($code);
    }

    public function setting(string $key, ?StoreId $store = null): SettingValueDto
    {
        return ($this->readSetting)($key, $store);
    }

    public function uploadMediaFor(ModuleUploadDto $upload): string
    {
        // The same use case as any other upload — the dedupe, the limits, the variants, the audit
        // entry — with the module's own permission checked in place of the media one (stage 2b, P1).
        return $this->uploadMedia->handle(new UploadMedia(
            $upload->visibility,
            $upload->path,
            $upload->originalFilename,
            forModule: $upload,
        ));
    }

    public function deleteMediaFor(ModuleDeleteDto $delete): void
    {
        // The same use case as the staff delete — the lock, the savepoint, the files after commit,
        // the event, the audit entry — with the module's own permission checked, private files
        // only, and any remaining use refusing it (B2B step 3, amendments 4 and 5).
        $this->deleteMedia->handle(new DeleteMedia($delete->mediaId, forModule: $delete));
    }

    public function media(string $mediaId): ?MediaDto
    {
        return $this->mediaReader->media($mediaId);
    }

    public function mediaUrls(string $mediaId): ?MediaUrlsDto
    {
        return $this->mediaReader->urls($mediaId);
    }

    public function mediaOf(array $mediaIds): array
    {
        return $this->mediaReader->mediaOf($mediaIds);
    }

    public function mediaUrlsOf(array $mediaIds): array
    {
        return $this->mediaReader->urlsOf($mediaIds);
    }

    public function recordAudit(AuditEntryDto $entry): void
    {
        $this->auditLog->record($entry);
    }

    public function recordImportedAudit(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void
    {
        $this->auditLog->recordImported($entry, $actor, $occurredAt);
    }

    public function addressCountries(string $locale): array
    {
        return self::byName($this->places->addressCountries(), $locale);
    }

    public function regions(string $countryCode, string $locale): array
    {
        return self::byName($this->places->regions(PlaceLocks::code($countryCode)), $locale);
    }

    public function cities(string $regionId, string $locale): array
    {
        return self::byName($this->places->cities($regionId), $locale);
    }

    public function districts(string $cityId, string $locale): array
    {
        return self::byName($this->places->districts($cityId), $locale);
    }

    public function citiesOf(string $countryCode, string $locale): array
    {
        return self::byName($this->places->citiesOf(PlaceLocks::code($countryCode)), $locale);
    }

    public function place(array $ids): array
    {
        return $this->places->places($ids);
    }

    public function storeCountries(StoreId $store): array
    {
        return $this->places->storeCountries($store->value);
    }

    public function countryStore(string $countryCode): ?StoreDto
    {
        $storeId = $this->places->countryStore(PlaceLocks::code($countryCode));

        return $storeId === null ? null : $this->directory->storeById($storeId);
    }

    /**
     * Alphabetical in the page's language, as that language sorts (stage 7 step 1, Q7).
     *
     * @template T of AddressCountryDto|PlaceDto
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function byName(array $items, string $locale): array
    {
        return NameOrder::sort($items, $locale, static fn (AddressCountryDto|PlaceDto $item): string => $item->name->in($locale));
    }
}
