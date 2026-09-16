<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * The generated sizes of a public image. The image keeps its whole shape — nothing is cropped —
 * and only its longest side is limited. A smaller original is never enlarged (owner's decision,
 * 2026-09-16).
 */
enum MediaSize: string
{
    case Thumb = 'THUMB';
    case Card = 'CARD';
    case Detail = 'DETAIL';
    case Zoom = 'ZOOM';

    public function longestEdge(): int
    {
        return match ($this) {
            self::Thumb => 200,
            self::Card => 600,
            self::Detail => 1200,
            self::Zoom => 2400,
        };
    }

    public function slug(): string
    {
        return strtolower($this->value);
    }
}
