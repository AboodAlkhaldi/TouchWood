<?php

declare(strict_types=1);

namespace Tests\Modules\Ops\Support;

use LogicException;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\ProviderFailed;
use Shared\Domain\ValueObject\StoreId;

/** A NewsletterList for step 7's tests: keeps every call as "add|remove|forget email [store locale]", or refuses while `$fails` is set. */
final class CapturedNewsletterList implements NewsletterList
{
    private static ?self $installed = null;

    /** @var list<string> */
    public array $calls = [];

    public bool $fails = false;

    public static function install(): self
    {
        $list = new self;
        app()->instance(NewsletterList::class, $list);
        self::$installed = $list;

        return $list;
    }

    /** The one install() bound. */
    public static function installed(): self
    {
        return self::$installed ?? throw new LogicException('CapturedNewsletterList::install() was not called.');
    }

    /** The last call kept. */
    public function last(): string
    {
        return $this->calls[count($this->calls) - 1] ?? throw new LogicException('No call was made.');
    }

    public function add(string $email, StoreId $store, string $locale): void
    {
        $this->call("add {$email} {$store->value} {$locale}");
    }

    public function remove(string $email, StoreId $store, string $locale): void
    {
        $this->call("remove {$email} {$store->value} {$locale}");
    }

    public function allow(string $email): void
    {
        $this->call("allow {$email}");
    }

    public function forget(string $email): void
    {
        $this->call("forget {$email}");
    }

    private function call(string $call): void
    {
        if ($this->fails) {
            throw new ProviderFailed('Brevo: the API could not be reached.');
        }

        $this->calls[] = $call;
    }
}
