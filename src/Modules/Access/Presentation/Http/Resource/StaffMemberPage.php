<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C2 - one staff member (frontend.md 3.3).
 *
 * What may be done to them is Access's answer, asked action by action, and each button is offered
 * only where the answer was yes. Offering is never allowing: every one of them checks again in its
 * own handler.
 */
#[TypeScript]
final class StaffMemberPage extends Data
{
    /**
     * @param  list<StaffActionRow>  $actions  what their role allows, grouped by area on the screen
     * @param  list<PermissionGroupRow>  $groups
     * @param  list<string>  $storeNames  the stores their role covers, already named
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $firstName,
        public string $lastName,
        public ?string $jobTitle,
        public ?string $email,
        public ?string $phone,
        public string $status,
        /** Their communication language: the one emails and codes go in (amendment 16). */
        public string $locale,
        public ?string $dateOfBirth,
        public ?string $country,
        public ?string $address,
        public ?string $avatarUrl,
        public bool $isAdmin,
        public bool $isSuperAdmin,
        public ?string $roleId,
        public string $roleName,
        public bool $allStores,
        public array $storeNames,
        public array $actions,
        public array $groups,
        /** Each button, offered only where Access said this reader may press it. */
        public bool $mayEditProfile,
        public bool $mayChangeEmail,
        public bool $mayChangeRole,
        public bool $mayDisable,
        public bool $mayEnable,
        public bool $mayResendInvitation,
        public bool $mayCancelInvitation,
        public bool $mayRefresh,
    ) {}
}
