<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\NewsletterList;
use Psr\Log\LoggerInterface;
use Shared\Domain\ValueObject\StoreId;

/**
 * For development and tests (ops.md §2.2): what would change in the newsletter's lists goes to the
 * application log. Never the driver of a live system - the binding refuses it in production.
 */
final readonly class LogNewsletterList implements NewsletterList
{
    public function __construct(
        private LoggerInterface $log,
    ) {}

    public function add(string $email, StoreId $store, string $locale): void
    {
        $this->log->info('Newsletter list (log driver): add', ['email' => $email, 'store' => $store->value, 'locale' => $locale]);
    }

    public function remove(string $email, StoreId $store, string $locale): void
    {
        $this->log->info('Newsletter list (log driver): remove', ['email' => $email, 'store' => $store->value, 'locale' => $locale]);
    }

    public function forget(string $email): void
    {
        $this->log->info('Newsletter list (log driver): forget', ['email' => $email]);
    }
}
