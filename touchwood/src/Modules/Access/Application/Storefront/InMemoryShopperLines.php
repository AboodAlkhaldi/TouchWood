<?php

declare(strict_types=1);

namespace Modules\Access\Application\Storefront;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Access\Public\Contracts\ShopperLine;
use Modules\Access\Public\Contracts\ShopperLines;
use Modules\Access\Public\Enums\AccountType;

/**
 * The lines modules put under the shop's header (access.md amendment 50), collected from their
 * service providers at boot.
 *
 * It keeps class names, not objects, and resolves each line when it is asked: this list lives for
 * the whole application, and a line may depend on the request it answers (the project's rule:
 * nothing that depends on the person acting is held by a singleton).
 */
final class InMemoryShopperLines implements ShopperLines
{
    /** @var list<class-string<ShopperLine>> */
    private array $lines = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $line): void
    {
        if (! is_subclass_of($line, ShopperLine::class)) {
            throw new LogicException("\"{$line}\" does not implement ".ShopperLine::class.'.');
        }

        if (in_array($line, $this->lines, true)) {
            throw new LogicException("The shopper line \"{$line}\" is registered twice.");
        }

        $this->lines[] = $line;
    }

    public function for(string $customerId, AccountType $accountType, bool $emailVerified): array
    {
        $said = [];

        foreach ($this->lines as $class) {
            $line = $this->container->make($class);

            // Checked when it was registered; resolved from the container, so asked again.
            if (! $line instanceof ShopperLine) {
                throw new LogicException("\"{$class}\" does not implement ".ShopperLine::class.'.');
            }

            $answer = $line->lineFor($customerId, $accountType, $emailVerified);

            if ($answer !== null) {
                $said[] = $answer;
            }
        }

        return $said;
    }
}
