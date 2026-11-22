<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\LabelTone;

/**
 * What adding and editing a label share: its look, as the form sends it.
 */
final class LabelInput
{
    /**
     * @throws InvalidCatalogAttribute
     */
    public static function tone(string $tone): LabelTone
    {
        return LabelTone::tryFrom($tone) ?? throw new InvalidCatalogAttribute('tone', 'one of the ten looks');
    }
}
