<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

/**
 * Why a customer's SMS code was sent (spec §1.3, §5.2): their first number, or a change. A change
 * keeps the old number live until the new one is verified.
 */
enum CustomerPhoneCodePurpose: string
{
    case Add = 'ADD';

    case Change = 'CHANGE';
}
