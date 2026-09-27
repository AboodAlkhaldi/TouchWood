<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\B2B\Domain\ValueObject\TypePosition;

/**
 * A kind of paper a company uploads — a VAT certificate, a commercial registration certificate
 * (b2b.md §1.3, handoff §8.1). A table staff manage; three ship with the migration, required.
 *
 * **A change here is never retroactive** (owner, 2026-09-26): making a type required, or
 * deactivating one, changes the next application and nothing else. A company already approved is
 * never asked for a new paper because a switch moved. So nothing on this class reaches an
 * application; the application reads the types when it is submitted.
 */
final class DocumentType
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private TypeName $name,
        private int $position,
        private bool $isActive,
        private bool $isRequired,
    ) {}

    /**
     * A type staff add later carries its own "required" switch (owner, 2026-09-19).
     *
     * @throws InvalidCompanyAttribute
     */
    public static function add(string $id, TypeName $name, int $position, bool $isRequired): self
    {
        return new self($id, $name, TypePosition::check($position), true, $isRequired);
    }

    public static function reconstitute(string $id, TypeName $name, int $position, bool $isActive, bool $isRequired): self
    {
        return new self($id, $name, $position, $isActive, $isRequired);
    }

    public function rename(TypeName $name): void
    {
        if ($name->equals($this->name)) {
            return;
        }

        $this->name = $name;
        $this->markChanged('name');
    }

    /**
     * @throws InvalidCompanyAttribute
     */
    public function moveTo(int $position): void
    {
        $position = TypePosition::check($position);

        if ($position === $this->position) {
            return;
        }

        $this->position = $position;
        $this->markChanged('position');
    }

    public function activate(): void
    {
        if ($this->isActive) {
            return;
        }

        $this->isActive = true;
        $this->markChanged('is_active');
    }

    public function deactivate(): void
    {
        if (! $this->isActive) {
            return;
        }

        $this->isActive = false;
        $this->markChanged('is_active');
    }

    public function require(): void
    {
        if ($this->isRequired) {
            return;
        }

        $this->isRequired = true;
        $this->markChanged('is_required');
    }

    public function makeOptional(): void
    {
        if (! $this->isRequired) {
            return;
        }

        $this->isRequired = false;
        $this->markChanged('is_required');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): TypeName
    {
        return $this->name;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    /**
     * A type that stops a submission when it is missing: required, and still offered. An inactive
     * type is asked of nobody, whatever its switch says.
     */
    public function isAskedFor(): bool
    {
        return $this->isActive && $this->isRequired;
    }

    /**
     * @return list<string> what changed since this was read, for the audit log
     */
    public function pullChanges(): array
    {
        $changed = $this->changed;
        $this->changed = [];

        return $changed;
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}
