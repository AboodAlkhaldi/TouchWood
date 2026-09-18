<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\MediaUsages;

/**
 * The modules that store media ids, collected from their service providers. The classes are
 * resolved only when media is deleted, so registering costs nothing on other requests.
 */
final class InMemoryMediaUsages implements MediaUsages
{
    /** @var array<class-string<MediaUsage>, string> class => registering module */
    private array $usages = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $module, string $usage): void
    {
        if (! is_subclass_of($usage, MediaUsage::class)) {
            throw new LogicException("Module \"{$module}\" registered \"{$usage}\", which does not implement ".MediaUsage::class.'.');
        }

        if (isset($this->usages[$usage])) {
            throw new LogicException("\"{$usage}\" is already registered by module \"{$this->usages[$usage]}\".");
        }

        $this->usages[$usage] = $module;
    }

    /**
     * @return list<MediaUsage>
     */
    public function all(): array
    {
        return array_map(
            fn (string $usage): MediaUsage => $this->container->make($usage),
            array_keys($this->usages),
        );
    }
}
