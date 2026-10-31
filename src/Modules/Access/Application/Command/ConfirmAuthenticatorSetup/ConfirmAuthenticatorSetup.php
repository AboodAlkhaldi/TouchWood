<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmAuthenticatorSetup;

/**
 * The first code from the app just set up, which proves it reads the QR code (amendment 91).
 */
final readonly class ConfirmAuthenticatorSetup
{
    public function __construct(
        #[\SensitiveParameter]
        public string $code,
    ) {}
}
