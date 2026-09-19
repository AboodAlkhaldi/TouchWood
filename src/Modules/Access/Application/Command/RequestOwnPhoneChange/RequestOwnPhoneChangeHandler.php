<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestOwnPhoneChange;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
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
        private PhoneVerification $verification,
        private Connection $db,
    ) {}

    public function handle(RequestOwnPhoneChange $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $phone = PhoneNumber::of($command->phone);

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
