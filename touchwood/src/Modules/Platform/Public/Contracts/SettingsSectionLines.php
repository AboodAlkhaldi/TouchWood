<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Where a module gives Platform the line at the top of its settings section (platform.md §1.3).
 * Register once, in your module's service provider; one line per module.
 */
interface SettingsSectionLines
{
    /**
     * @param  string  $module  the registering module, e.g. "b2b" — the section it heads
     * @param  string  $line  the class name of a SettingsSectionLine, checked when registered
     *
     * @throws \LogicException for a class that is not a SettingsSectionLine, or a module's second
     */
    public function register(string $module, string $line): void;
}
