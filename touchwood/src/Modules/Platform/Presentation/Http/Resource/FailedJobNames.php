<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Illuminate\Support\Str;

/**
 * Where a queued class's name for people sits (frontend.md E7): in the words of the module that owns
 * it, `{module}::jobs.{name}` — `Modules\B2B\Infrastructure\Listener\AnonymizeCompany` at
 * `b2b::jobs.anonymize_company`, a trailing "Job" left off. A class outside the modules has none, and
 * the screen shows its technical name.
 */
final class FailedJobNames
{
    public static function keyFor(string $class): ?string
    {
        if (preg_match('/\AModules\\\\([A-Za-z0-9]+)\\\\(?:[A-Za-z0-9]+\\\\)*([A-Za-z0-9]+)\z/', $class, $match) !== 1) {
            return null;
        }

        $name = (string) preg_replace('/Job\z/', '', $match[2]);

        return strtolower($match[1]).'::jobs.'.Str::snake($name);
    }
}
