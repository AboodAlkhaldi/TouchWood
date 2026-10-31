<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChooseSignInMethod;

/**
 * A staff member's own sign-in method, while both are ticked (amendment 91): "SMS" or "AUTHENTICATOR".
 */
final readonly class ChooseSignInMethod
{
    public function __construct(
        public string $method,
    ) {}
}
