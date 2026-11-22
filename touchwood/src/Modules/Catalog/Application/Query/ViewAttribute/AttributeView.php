<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewAttribute;

use Modules\Catalog\Application\Query\Lists\AttributeRow;
use Modules\Catalog\Application\Query\Lists\ValueRow;

/**
 * An attribute, its values in order, and whether the reader may change them.
 */
final readonly class AttributeView
{
    /**
     * @param  list<ValueRow>  $values
     */
    public function __construct(
        public AttributeRow $attribute,
        public array $values,
        public bool $mayChange,
    ) {}
}
