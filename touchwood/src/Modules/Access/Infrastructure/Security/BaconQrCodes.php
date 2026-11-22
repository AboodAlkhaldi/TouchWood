<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Modules\Access\Application\Security\QrCodes;

/**
 * QR codes as SVG, drawn here by bacon/bacon-qr-code (BSD-2-Clause; amendment 91): the secret inside
 * never goes to a service that draws them.
 */
final readonly class BaconQrCodes implements QrCodes
{
    /** Pixels square, before the page scales it. */
    private const int SIZE = 240;

    public function svg(#[\SensitiveParameter] string $text): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(self::SIZE, 1), new SvgImageBackEnd)))->writeString($text);
    }
}
