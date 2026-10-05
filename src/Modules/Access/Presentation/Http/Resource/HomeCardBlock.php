<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One card on the admin home, as a module registered it (platform.md §2.6), its words already in the
 * reader's language.
 */
#[TypeScript]
final class HomeCardBlock extends Data
{
    public function __construct(
        /** `{module}.{key}`: unique on the page. */
        public string $key,
        public string $title,
        /** @var list<HomeFigureBlock> */
        public array $figures,
        /** @var list<HomeRowBlock> */
        public array $rows,
        public ?string $rowsLabel,
        public ?string $href,
        /** The words on the way to the card's whole screen ("View Companies"), with $href. */
        public ?string $openLabel,
    ) {}
}
