<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Modules\Sync\Application\Report\SyncWords;

/**
 * Sync's words from its own lang files (`sync::…`), in the app's language.
 */
final readonly class LangSyncWords implements SyncWords
{
    public function __construct(
        private Application $app,
    ) {}

    public function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }

    public function line(string $key, array $replace = []): string
    {
        $line = trans("sync::{$key}", array_map('strval', $replace));

        return is_string($line) ? $line : $key;
    }
}
