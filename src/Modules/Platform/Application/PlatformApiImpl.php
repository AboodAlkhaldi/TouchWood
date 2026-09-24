<?php

declare(strict_types=1);

namespace Modules\Platform\Application;

use DateTimeImmutable;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\ReadSetting;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Modules\Platform\Public\Dto\CurrencyDto;
use Modules\Platform\Public\Dto\MediaDto;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\ModuleUploadDto;
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
    ) {}

    public function store(StoreId $id): ?StoreDto
    {
        return $this->directory->storeById($id->value);
    }

    public function storeByCode(string $code): ?StoreDto
    {
        return $this->directory->storeByCode($code);
    }

    public function stores(): array
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

    public function media(string $mediaId): ?MediaDto
    {
        return $this->mediaReader->media($mediaId);
    }

    public function mediaUrls(string $mediaId): ?MediaUrlsDto
    {
        return $this->mediaReader->urls($mediaId);
    }

    public function recordAudit(AuditEntryDto $entry): void
    {
        $this->auditLog->record($entry);
    }

    public function recordImportedAudit(AuditEntryDto $entry, Actor $actor, DateTimeImmutable $occurredAt): void
    {
        $this->auditLog->recordImported($entry, $actor, $occurredAt);
    }
}
