<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAccount;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * What a staff member's own settings screen shows them (frontend.md §3.2).
 *
 * It answers about the person asking and nobody else: the id comes from who is signed in, so
 * there is no way to spell somebody else's account here. The permission is the one every staff
 * member holds for their own account, checked so the read and the four things the screen can
 * change agree about who may be there at all.
 */
final readonly class MyAccountForStaff
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private NotificationPreferenceRepository $preferences,
        private PlatformApi $platform,
    ) {}

    public function forCurrentStaff(): MyAccountDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

        $profile = $staff->profile();
        $pending = $this->tokens->pendingEmailChange($staffId);

        return new MyAccountDto(
            id: $staff->id(),
            email: $staff->email()->value,
            firstName: $profile->firstName,
            lastName: $profile->lastName,
            jobTitle: $profile->jobTitle,
            dateOfBirth: $profile->dateOfBirth->format('Y-m-d'),
            country: $profile->country->value,
            address: $profile->address,
            locale: $staff->language()->value,
            avatarMediaId: $staff->avatarMediaId(),
            avatarUrl: $this->avatarUrl($staff->avatarMediaId()),
            // In full, not masked (stage 2b step 2, 2026-09-23). This page is reached only after a
            // password and an SMS code, and it already shows the name, email, date of birth and
            // address beside it; masking the phone alone would be inconsistent, and it would stop
            // the person checking which number is on file before changing it. P4 masks the
            // sign-in code screen, which is shown before anybody has proved who they are.
            phone: $staff->phone()?->value,
            isSuperAdmin: $staff->isSuperAdmin(),
            // An expired change is no change: the link behind it is dead, so the screen must not
            // go on claiming an address is on its way.
            pendingEmail: $pending === null || $pending->isExpired(CarbonImmutable::now()) ? null : $pending->newEmail->value,
            notifications: $this->preferences->of($staffId),
        );
    }

    /**
     * The picture, asked of Platform as any other module asks for media, exactly as the sidebar
     * asks for it: the thumb, smallest format first, and nothing at all while the variants are
     * still being generated — which is a moment after the upload, and reads as no picture rather
     * than as a broken one.
     */
    private function avatarUrl(?string $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $thumb = $this->platform->mediaUrls($mediaId)?->variants[MediaSize::Thumb->slug()] ?? null;

        if (! is_array($thumb)) {
            return null;
        }

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $thumb[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }
}
