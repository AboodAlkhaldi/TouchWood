<?php

declare(strict_types=1);

namespace Modules\Access\Public\Dto;

use Modules\Access\Public\Enums\AccountType;

/**
 * A page another module adds to a customer's account (access.md amendment 50): B2B's company page,
 * say. It is listed beside the account's own tabs, for the accounts it is meant for.
 */
final readonly class CustomerAccountPageDto
{
    /**
     * @param  string  $module  the module that owns the page, e.g. "b2b"
     * @param  string  $key  unique within that module; its name is read from
     *                       `{module}::account_pages.{key}` in Arabic and English
     * @param  string  $routeName  the named shop route the entry opens; it sits under
     *                             /{store}/{locale}, which the link fills in
     * @param  AccountType|null  $onlyFor  the one account type it is offered to, or null for every
     *                                     account
     * @param  int  $position  where it sits among the other modules' pages, lowest first
     */
    public function __construct(
        public string $module,
        public string $key,
        public string $routeName,
        public ?AccountType $onlyFor = null,
        public int $position = 0,
    ) {}

    /** The page's full name, `{module}.{key}`: how the account menu and `return` name it. */
    public function name(): string
    {
        return "{$this->module}.{$this->key}";
    }

    public function labelKey(): string
    {
        return "{$this->module}::account_pages.{$this->key}";
    }
}
