<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Setting up an authenticator app, or changing it (amendment 91): the password, then the QR code with
 * the key to type by hand, then one code from the app.
 */
#[TypeScript]
final class AuthenticatorSetupPage extends Data
{
    public function __construct(
        /** An app is set up already: this replaces it once the new one's code is right. */
        public bool $hasApp,
        /** The panel waits for this setup: the page offers signing out rather than going back. */
        public bool $required,
        /** The QR code as an SVG document, drawn on our server; null until the password is given. */
        public ?string $qrSvg,
        /** The secret in groups of four, for an app that cannot scan. */
        public ?string $manualKey,
    ) {}
}
