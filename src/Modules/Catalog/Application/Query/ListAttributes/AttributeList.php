<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListAttributes;

use Modules\Catalog\Application\Query\Lists\AttributeRow;

/**
 * The attributes, and whether the reader may change them — the job with All stores (§1.7).
 */
final readonly class AttributeList
{
    /**
     * @param  list<AttributeRow>  $attributes
     */
    public function __construct(
        public array $attributes,
        public bool $mayChange,
    ) {}
}
