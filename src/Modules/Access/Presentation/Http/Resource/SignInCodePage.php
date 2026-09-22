<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A3 and A7 - the code screen (frontend.md 3.1).
 *
 * How many boxes, how long until another code may be asked for and how many days a browser is
 * trusted all come from settings, never from the screen: an admin changes them without anybody
 * touching this file.
 */
#[TypeScript]
final class SignInCodePage extends Data
{
    public function __construct(
        /** Masked by Access before it reaches the page: the last three digits only (stage 2b, P4). */
        public ?string $maskedPhone,
        public int $length,
        public int $trustDays,
        /** Seconds until another code may be asked for; 0 means now. */
        public int $resendIn,
        /** Where the code is sent: the sign-in flow, or an invitation being confirmed. */
        public string $action,
        public string $resendAction,
    ) {}
}
