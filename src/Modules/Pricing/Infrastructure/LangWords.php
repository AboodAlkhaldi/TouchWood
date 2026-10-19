<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Modules\Pricing\Application\Prices\Words;

/**
 * Pricing's words from its lang files (`Presentation/lang/{ar,en}`), in the app's locale.
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
        $line = trans("pricing::{$key}", array_map('strval', $replace));

        return is_string($line) ? $line : $key;
    }
}
