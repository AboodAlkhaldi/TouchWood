<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\Model;

use Modules\Platform\Domain\Exception\InvalidPlace;
use Modules\Platform\Domain\ValueObject\IsoCountries;

/**
 * A country a customer may choose in an address (platform.md, stage 7 (a); owner, 2026-10-27 R1).
 * Never deleted: retired, and restored (stage 7 step 1, Q3).
 */
final class AddressCountry
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        public readonly string $code,
        private bool $retired,
    ) {}

    public static function add(string $code): self
    {
        if (! IsoCountries::exists($code)) {
            throw new InvalidPlace('country', 'expected an ISO 3166-1 country code');
        }

        return new self($code, false);
    }

    public static function reconstitute(string $code, bool $retired): self
    {
        return new self($code, $retired);
    }

    public function isRetired(): bool
    {
        return $this->retired;
    }

    public function retire(): void
    {
        $this->mark(true);
    }

    public function restore(): void
    {
        $this->mark(false);
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        $changed = $this->changed;
        $this->changed = [];

        return $changed;
    }

    private function mark(bool $retired): void
    {
        if ($this->retired !== $retired) {
            $this->retired = $retired;
            $this->changed = ['retired'];
        }
    }
}
