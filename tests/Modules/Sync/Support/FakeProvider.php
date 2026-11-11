<?php

declare(strict_types=1);

namespace Tests\Modules\Sync\Support;

use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapter;
use Modules\Sync\Domain\ValueObject\ProviderReason;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;

/**
 * The pretend provider Sync's tests read (sync.md §2.1): bound in Odoo's place, it answers what a test
 * gives it, fails when told to, and remembers each connection it was tested with.
 */
final class FakeProvider implements ProviderAdapter
{
    /** @var list<Connection> */
    public array $tested = [];

    /**
     * @param  list<ProviderValue>  $values
     * @param  list<string>  $fields
     */
    public function __construct(
        public string|ProviderReason|null $failure = null,
        public array $values = [],
        public array $fields = [],
    ) {}

    /**
     * In place of Odoo's adapter, for the rest of the test.
     *
     * @param  list<string>  $fields
     */
    public static function bind(?string $failure = null, array $fields = []): self
    {
        $fake = new self($failure, [], $fields);
        app()->instance(OdooAdapter::class, $fake);
        self::$bound = $fake;

        return $fake;
    }

    private static ?self $bound = null;

    /** The one the test bound last. */
    public static function bound(): self
    {
        return self::$bound ?? throw new \LogicException('No pretend provider bound.');
    }

    public function fields(): array
    {
        return $this->fields;
    }

    /** Whether it answers as a connection that is built (Q38): yes, unless a test says otherwise. */
    public bool $ready = true;

    public function ready(): bool
    {
        return $this->ready;
    }

    /** Any address the shared rule takes: the pretend provider has no shape of its own. */
    public function address(string $address): string
    {
        return $address;
    }

    /** @var (callable(): mixed)|null run once, during the next test - what happens while the provider answers */
    public $whileTesting = null;

    public function test(Connection $connection): void
    {
        $this->tested[] = $connection;

        if ($this->whileTesting !== null) {
            $meanwhile = $this->whileTesting;
            $this->whileTesting = null;
            $meanwhile();
        }

        if ($this->failure !== null) {
            throw new ConnectionFailed($this->failure);
        }
    }

    /** @var list<list<string>> the codes each read asked of it */
    public array $reads = [];

    /** @var (callable(): mixed)|null run once, during the next read - what happens while the provider answers */
    public $whileReading = null;

    /**
     * Its values for the codes asked, as Odoo answers (sync.md §2.4): a code not asked is left out - unless
     * the test lets it answer everything it was given (`answersUnasked`), as another provider might.
     */
    public bool $answersUnasked = false;

    public function read(Connection $connection, array $codes): iterable
    {
        $this->reads[] = $codes;

        if ($this->whileReading !== null) {
            $meanwhile = $this->whileReading;
            $this->whileReading = null;
            $meanwhile();
        }

        if ($this->failure !== null) {
            throw new ConnectionFailed($this->failure);
        }

        $asked = array_fill_keys(array_map(trim(...), $codes), true);

        return $this->answersUnasked ? $this->values : array_values(array_filter($this->values, static fn (ProviderValue $value): bool => isset($asked[trim($value->code)])));
    }
}
