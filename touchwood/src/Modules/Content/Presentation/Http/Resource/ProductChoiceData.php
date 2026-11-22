<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A product a post or project links, or may (content.md §1.12, §1.13): its names, and whether it is archived since. */
#[TypeScript]
final class ProductChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public bool $archived,
    ) {}

    /**
     * @param  array{id: string, nameAr: string, nameEn: string|null, archived: bool}  $product
     */
    public static function fromRead(array $product): self
    {
        return new self($product['id'], $product['nameAr'], $product['nameEn'], $product['archived']);
    }
}
