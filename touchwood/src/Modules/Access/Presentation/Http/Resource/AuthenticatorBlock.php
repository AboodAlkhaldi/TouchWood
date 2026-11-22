<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The authenticator app on the account's Security tab (amendment 91).
 */
#[TypeScript]
final class AuthenticatorBlock extends Data
{
    public function __construct(
        /** Which methods the Super Admins' setting has ticked; with both, the person chooses. */
        public bool $smsEnabled,
        public bool $authenticatorEnabled,
        public bool $hasApp,
        /** When the app in use was set up, as a UTC date and time with its offset. */
        public ?string $confirmedAt,
        /** "SMS" or "AUTHENTICATOR": the second step they take now. */
        public string $method,
        public int $recoveryCodesLeft,
    ) {}
}
