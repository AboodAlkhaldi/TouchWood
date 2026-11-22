<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChooseSignInMethod;

/**
 * A staff member's own sign-in method, while both are ticked (amendment 91): "SMS" or "AUTHENTICATOR".
 */
final readonly class ChooseSignInMethod
{
    /**
     * @param  string|null  $currentPassword  asked when SMS is chosen (owner, 2026-10-31)
     */
    public function __construct(
        public string $method,
        #[\SensitiveParameter]
        public ?string $currentPassword = null,
        public string $ip = '',
    ) {}
}
