<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Settings;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\SettingsSectionLine;
use Modules\Platform\Public\Contracts\SettingsSectionLines;

/**
 * The modules' lines for their settings sections, collected from their service providers
 * (platform.md §1.3). The classes are resolved only when the settings page is shown.
 */
final class InMemorySettingsSectionLines implements SettingsSectionLines
{
    /** @var array<string, class-string<SettingsSectionLine>> module => class */
    private array $lines = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $line): void
    {
        if (! is_subclass_of($line, SettingsSectionLine::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$line}\", which does not implement ".SettingsSectionLine::class.'.');
        }

        if (isset($this->lines[$module])) {
            throw new LogicException("Module \"{$module}\" already has a settings line, \"{$this->lines[$module]}\".");
        }

        $this->lines[$module] = $line;
    }

    public function for(string $module): ?SettingsSectionLine
    {
        $line = $this->lines[$module] ?? null;

        return $line === null ? null : $this->container->make($line);
    }
}
