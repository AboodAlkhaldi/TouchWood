<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Service;

/** A whole percentage as the exact decimal `Money::multiply` takes — 15 → "0.15", 100 → "1.00" — never a float. */
final class Percent
{
    public static function fraction(int $percent): string
    {
        return intdiv($percent, 100).'.'.str_pad((string) ($percent % 100), 2, '0', STR_PAD_LEFT);
    }
}
