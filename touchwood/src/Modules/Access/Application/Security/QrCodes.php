<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

/**
 * A QR code drawn on our own server (amendment 91): what it holds - an authenticator secret - never
 * leaves it.
 */
interface QrCodes
{
    /**
     * The QR code as an SVG document.
     */
    public function svg(string $text): string;
}
