<?php

declare(strict_types=1);

namespace Tests\Modules\Sync\Support;

use DateTimeImmutable;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\Model\Connection;
use Modules\Sync\Domain\Provider\ProviderAdapter;
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
        public ?string $failure = null,
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

        return $fake;
    }

    public function fields(): array
    {
        return $this->fields;
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

    public function read(Connection $connection, ?array $codes, ?DateTimeImmutable $changedSince): iterable
    {
        if ($this->failure !== null) {
            throw new ConnectionFailed($this->failure);
        }

        return $codes === null ? $this->values : array_values(array_filter($this->values, static fn (ProviderValue $value): bool => in_array($value->code, $codes, true)));
    }
}
