<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Public\Dto\StaffDto;

final class StaffMapper
{
    public static function toDto(StaffUser $staff): StaffDto
    {
        return new StaffDto(
            $staff->id(),
            $staff->firstName(),
            $staff->lastName(),
            $staff->email()->value,
            $staff->phone()?->value,
            $staff->language()->value,
            $staff->status(),
            $staff->isSuperAdmin(),
        );
    }
}
