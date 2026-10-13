<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Settings;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\SettingGuard;
use Modules\Platform\Public\Contracts\SettingGuards;
use Shared\Domain\ValueObject\StoreId;

/**
 * The guards modules register over their settings (platform.md §2.2), asked by `UpdateSetting`.
 */
final class InMemorySettingGuards implements SettingGuards
{
    /** @var array<string, list<class-string<SettingGuard>>> */
    private array $guards = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $key, string $guard): void
    {
        $this->guards[$key][] = $guard;
    }

    public function check(string $key, ?StoreId $store, mixed $from, mixed $to): void
    {
        foreach ($this->guards[$key] ?? [] as $class) {
            $guard = $this->container->make($class);

            if (! $guard instanceof SettingGuard) {
                throw new LogicException("{$class} is registered as a guard of {$key} but is not a SettingGuard.");
            }

            $guard->check($key, $store, $from, $to);
        }
    }
}
