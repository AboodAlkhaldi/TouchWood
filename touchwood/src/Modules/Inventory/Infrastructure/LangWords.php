<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Modules\Inventory\Application\Stock\Words;

/**
 * Inventory's words from its own lang files (`inventory::…`), in the app's language.
 */
final readonly class LangWords implements Words
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
        $line = trans("inventory::{$key}", array_map('strval', $replace));

        return is_string($line) ? $line : $key;
    }
}
