<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAuthenticator;

/**
 * The setup page (amendment 91): the password first; once given in this browser, the QR code and the
 * key to type by hand, for 15 minutes.
 */
final readonly class AuthenticatorSetupDto
{
    public function __construct(
        /** An app is set up already: this setup changes it. */
        public bool $hasApp,
        /** The panel waits for this setup: the app is their method and none is set up. */
        public bool $required,
        /** The QR code as an SVG document; null until the password is given. */
        public ?string $qrSvg,
        /** The same secret, in groups of four, for an app that cannot scan; null with the QR code. */
        public ?string $manualKey,
    ) {}
}
