<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

/**
 * Each product's own fate as a deactivation's form posts it (catalog.md §1.5, §1.6):
 * `products[<id>][choice]` and `products[<id>][move_to]`. Only the shape is read here — keys as text,
 * each entry an array; whether a choice is one the list allows, and where it may move, is
 * `ProductFates`' to say, in the panel's words.
 */
final class Fates
{
    /**
     * @return array<string, mixed>
     */
    public static function of(mixed $posted): array
    {
        if (! is_array($posted)) {
            return [];
        }

        $fates = [];

        foreach ($posted as $productId => $fate) {
            $fates[(string) $productId] = $fate;
        }

        return $fates;
    }
}
