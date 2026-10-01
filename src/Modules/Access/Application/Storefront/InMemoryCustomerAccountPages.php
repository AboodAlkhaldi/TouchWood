<?php

declare(strict_types=1);

namespace Modules\Access\Application\Storefront;

use LogicException;
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

            $this->pages[] = $page;
        }
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
