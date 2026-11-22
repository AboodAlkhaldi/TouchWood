<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * MyFatoorah's 401 "The token does not have the required permissions!" on a key's test (§1.2, notes
 * §0 #18): names what to switch on for the key in MyFatoorah's portal.
 */
final class KeyPermissionMissing extends PaymentsError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("The key does not have the {$this->permission} permission.");
    }

    public function type(): string
    {
        return 'payments.key_permission_missing';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['permission' => $this->permission];
    }
}
