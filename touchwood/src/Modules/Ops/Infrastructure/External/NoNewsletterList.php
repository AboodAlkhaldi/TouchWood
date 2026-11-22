<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\External;

use Modules\Ops\Application\Messages\NewsletterList;
use Shared\Domain\ValueObject\StoreId;

/**
 * Production with email not set up (owner, 2026-11-22; ops.md §7): the newsletter's lists are not kept at any provider,
 * and nothing is written anywhere - not to the log either, which `LogNewsletterList` would do with every address. Our own
 * subscribers table is unchanged; the Super Admins' red notice (EmailBanner) says what is missing. When email is set up,
 * the next change is made at Brevo as ever, and a list is made when first used.
 */
final readonly class NoNewsletterList implements NewsletterList
{
    public function ready(StoreId $store, string $locale): void {}

    public function forgetList(StoreId $store, string $locale): void {}

    public function add(#[\SensitiveParameter] string $email, StoreId $store, string $locale): void {}

    public function remove(#[\SensitiveParameter] string $email, StoreId $store, string $locale): void {}

    public function allow(#[\SensitiveParameter] string $email): void {}

    public function forget(#[\SensitiveParameter] string $email): void {}
}
