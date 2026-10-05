<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Home;

use Modules\Platform\Application\FailedJobs\FailedJobs;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;

/**
 * Platform's card on the admin home, Stores and System (platform.md §9.8; the owner's pick,
 * 2026-10-05): stores on and off, failed jobs, and storage used. Each figure is shown only to a
 * reader holding its own permission - the card itself to one holding any of them.
 *
 * Stores on and off is an all-stores figure: one store's state is already in the store switcher.
 * Off stores are counted only for whoever may switch stores, as the stores screen lists them only
 * to them (§1.6). Failed jobs and storage belong to no store, so they read the same in either scope.
 */
final readonly class StoresAndSystemCard implements HomeCard
{
    /** @var list<string> any one of which lets a reader see the storage used */
    public const array MEDIA = [PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::MEDIA_UPDATE, PlatformPermissions::MEDIA_DELETE];

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
        private FailedJobs $failedJobs,
        private MediaReader $media,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $figures = [];

        if ($scope->isAllStores() && $this->authorizer->storesWith(PlatformPermissions::STORE_VIEW) === null) {
            $stores = $this->directory->stores();
            $on = count(array_filter($stores, static fn ($store): bool => $store->isActive));
            $figures[] = new HomeFigure('stores_on', $on, href: route('platform.admin.stores', absolute: false));

            if ($this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== []) {
                $figures[] = new HomeFigure('stores_off', count($stores) - $on, href: route('platform.admin.stores', absolute: false));
            }
        }

        if ($this->authorizer->storesWith(PlatformPermissions::JOBS_MANAGE) !== []) {
            $failed = $this->failedJobs->count();
            $figures[] = new HomeFigure('failed_jobs', $failed, href: route('platform.admin.failed_jobs', absolute: false), tone: $failed > 0 ? 'amber' : null);
        }

        foreach (self::MEDIA as $permission) {
            if ($this->authorizer->storesWith($permission) !== []) {
                $figures[] = new HomeFigure('storage', $this->media->totalBytes(), HomeFigure::BYTES, href: route('platform.admin.media', absolute: false));

                break;
            }
        }

        return $figures === [] ? null : new HomeCardData($figures);
    }
}
