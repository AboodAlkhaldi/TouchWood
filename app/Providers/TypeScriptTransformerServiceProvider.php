<?php

declare(strict_types=1);

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;

/**
 * TypeScript types generated from the PHP that produces a page's data (frontend.md §1.6).
 *
 * A renamed field then breaks the type check, in the build, rather than the live page. That is the
 * whole point of it: a screen and its data are written in two languages, and only a generated type
 * keeps the two honest.
 *
 * It reads `src/`, not `app/`: page data classes live in the module that owns the data, in its
 * `Presentation/Http/Resource/`, because a page's shape is that module's business (handoff §4.3).
 * A class is only transformed when it says so with `#[TypeScript]`, so nothing in the system leaks
 * into the browser's types by accident.
 *
 * `composer check` fails when the generated file is out of date - see the `types:check` script.
 */
final class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->transformer(AttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(base_path('src'))
            ->outputDirectory(base_path('resources/js/types'))
            ->writer(new ModuleWriter('generated', 'index.ts'));
    }
}
