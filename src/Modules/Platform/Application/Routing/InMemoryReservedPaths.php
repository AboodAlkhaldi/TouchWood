<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Routing;

use LogicException;
use Modules\Platform\Public\Contracts\ReservedPaths;

/**
 * Every reserved top-level segment, collected from the modules' service providers while they
 * register. Frozen once Platform has built the `{store}` route pattern from it at boot.
 */
final class InMemoryReservedPaths implements ReservedPaths
{
    private const string SEGMENT = '/\A[a-z][a-z0-9_-]{0,63}\z/';

    /** @var array<string, list<string>> segment => modules that reserved it */
    private array $reserved = [];

    private bool $frozen = false;

    public function reserve(string $module, string ...$segments): void
    {
        if ($this->frozen) {
            throw new LogicException("Module \"{$module}\" reserved a path after boot. Reserve paths in the service provider's register().");
        }

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new LogicException("\"{$segment}\" is not a valid top-level path segment (module \"{$module}\").");
            }

            if (! in_array($module, $this->reserved[$segment] ?? [], true)) {
                $this->reserved[$segment][] = $module;
            }
        }
    }

    public function isReserved(string $segment): bool
    {
        return isset($this->reserved[$segment]);
    }

    /**
     * @return list<string> sorted
     */
    public function all(): array
    {
        $segments = array_map(strval(...), array_keys($this->reserved));
        sort($segments);

        return $segments;
    }

    /**
     * The requirement for `{store}`: 2 to 8 lowercase letters that are not a reserved segment. The
     * lookahead stops at the end of the segment, so /admin/login is excluded as well as /admin,
     * while a store code that merely starts like one ("upx") stays reachable.
     */
    public function routePattern(): string
    {
        $segments = array_map(fn (string $segment): string => preg_quote($segment, '#'), $this->all());

        return $segments === []
            ? '[a-z]{2,8}'
            : '(?!(?:'.implode('|', $segments).')(?![a-z0-9_-]))[a-z]{2,8}';
    }

    /**
     * Called by Platform at boot, once the route pattern is built: a later reservation would be
     * missing from it, so it fails loudly instead.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}
