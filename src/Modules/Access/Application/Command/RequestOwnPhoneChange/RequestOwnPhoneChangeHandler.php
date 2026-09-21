<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestOwnPhoneChange;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Security\PhoneVerification;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class RequestOwnPhoneChangeHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private OwnPasswordCheck $confirmation,
        private PhoneVerification $verification,
        private Connection $db,
    ) {}

    public function handle(RequestOwnPhoneChange $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $phone = PhoneNumber::of($command->phone);

        // The number is where the sign-in code goes, so it is a change of the second factor:
        // the current password is proved first, and a wrong one is counted (owner, 2026-09-21).
        $this->confirmation->confirm(
            $this->staff->find($staffId) ?? throw new StaffNotFound($staffId),
            $command->currentPassword,
            $command->ip,
        );

        $this->db->transaction(function () use ($staffId, $phone): void {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

            if ($staff->phone() !== null && $staff->phone()->equals($phone) && $staff->phoneVerifiedAt() !== null) {
                throw new InvalidAccessAttribute('phone', 'the same as the current one');
            }

            // Refused when the number is entered, not after the code (spec §1.3).
            if ($this->staff->phoneInUse($phone, $staff->id())) {
                throw new PhoneAlreadyInUse;
            }

            $this->verification->send($staff->id(), $phone, PhoneCodePurpose::Change, $staff->language(), CarbonImmutable::now());
        });
    }
}
