<?php

declare(strict_types=1);

namespace Modules\Access\Application\Storefront;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Access\Public\Contracts\CustomerAccountPageCondition;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Enums\AccountType;

/**
 * The pages other modules add to a customer's account, collected from their service providers at
 * boot (access.md amendment 50), the way Platform collects admin menu entries.
 */
final class InMemoryCustomerAccountPages implements CustomerAccountPages
{
    /** @var list<CustomerAccountPageDto> */
    private array $pages = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(CustomerAccountPageDto ...$pages): void
    {
        foreach ($pages as $page) {
            foreach ($this->pages as $registered) {
                if ($registered->module === $page->module && $registered->key === $page->key) {
                    throw new LogicException("The account page \"{$page->module}.{$page->key}\" is registered twice.");
                }

                if ($registered->routeName === $page->routeName) {
                    throw new LogicException("The route \"{$page->routeName}\" is already the account page \"{$registered->module}.{$registered->key}\".");
                }
            }

            if ($page->condition !== null && ! is_subclass_of($page->condition, CustomerAccountPageCondition::class)) {
                throw new LogicException("The account page \"{$page->module}.{$page->key}\" names a condition that is not a CustomerAccountPageCondition.");
            }

            $this->pages[] = $page;
        }
    }

    /**
     * Resolved each time it is asked, as a shopper line is: this list lives for the whole
     * application, and a condition depends on the request it answers.
     *
     * @param  class-string  $class
     */
    private function condition(string $class): CustomerAccountPageCondition
    {
        $condition = $this->container->make($class);

        // Checked when it was registered; resolved from the container, so asked again.
        if (! $condition instanceof CustomerAccountPageCondition) {
            throw new LogicException("{$class} is not a CustomerAccountPageCondition.");
        }

        return $condition;
    }

    public function for(AccountType $type): array
    {
        $offered = array_values(array_filter(
            $this->pages,
            static fn (CustomerAccountPageDto $page): bool => $page->onlyFor === null || $page->onlyFor === $type,
        ));

        usort($offered, static fn (CustomerAccountPageDto $a, CustomerAccountPageDto $b): int => [$a->position, $a->module, $a->key] <=> [$b->position, $b->module, $b->key]);

        return $offered;
    }

    public function offeredTo(AccountType $type, string $customerId): array
    {
        return array_values(array_filter(
            $this->for($type),
            fn (CustomerAccountPageDto $page): bool => $page->condition === null || $this->condition($page->condition)->offers($customerId),
        ));
    }

    public function find(AccountType $type, string $name): ?CustomerAccountPageDto
    {
        foreach ($this->for($type) as $page) {
            if ($page->name() === $name) {
                return $page;
            }
        }

        return null;
    }
}
