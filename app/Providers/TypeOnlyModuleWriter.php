<?php

declare(strict_types=1);

namespace App\Providers;

use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\Location;
use Spatie\TypeScriptTransformer\Data\WriteableFile;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;

/**
 * The generated types' files, each importing another's types **as types** (`import type { … }`).
 *
 * The files hold nothing but types, and the project's TypeScript reads imports as written
 * (`verbatimModuleSyntax`), so a plain `import { MoneyData }` fails the type check: an import that names
 * only a type must say so. The package writes plain imports, with no option to do otherwise; this writes
 * them as type imports. The first file to import another's is Catalog's page data, which shows
 * Platform's `MoneyData` (pricing.md §9.3 S4).
 */
final class TypeOnlyModuleWriter extends ModuleWriter
{
    protected function writeLocation(Location $location, TransformedCollection $transformedCollection): WriteableFile
    {
        $file = parent::writeLocation($location, $transformedCollection);

        return new WriteableFile($file->path, (string) preg_replace('/^import \{/m', 'import type {', $file->contents), $file->changed);
    }
}
