<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\B2B\Domain\ValueObject\TypePosition;

/**
 * A kind of paper a company uploads — a VAT certificate, a commercial registration certificate
 * (b2b.md §1.3, handoff §8.1). A table staff manage, **one list per store** (amendment 5): a paper
 * in one country is not one in another. Every store starts with the same three, required
 * (StartingTypes).
 *
 * **A change here is never retroactive** (owner, 2026-09-26): making a type required, or
 * deactivating one, changes the next application and nothing else. A company already approved is
 * never asked for a new paper because a switch moved. So nothing on this class reaches an
 * application; the application reads the types when it is submitted. Deactivating, staff choose how
 * it looks to new applications: hidden, or greyed out (amendment 5).
 */
final class DocumentType
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private readonly string $storeId,
        private TypeName $name,
        private int $position,
        private bool $isActive,
        private ?InactiveTypeDisplay $inactiveDisplay,
        private bool $isRequired,
    ) {}

    /**
     * A type staff add later carries its own "required" switch (owner, 2026-09-19).
     *
     * @param  string  $storeId  the store whose list it joins
     *
     * @throws InvalidCompanyAttribute
     */
    public static function add(string $id, string $storeId, TypeName $name, int $position, bool $isRequired): self
    {
        return new self($id, strtolower($storeId), $name, TypePosition::check($position), true, null, $isRequired);
    }

    public static function reconstitute(string $id, string $storeId, TypeName $name, int $position, bool $isActive, ?InactiveTypeDisplay $inactiveDisplay, bool $isRequired): self
    {
        return new self($id, $storeId, $name, $position, $isActive, $inactiveDisplay, $isRequired);
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

    /**
     * Offered again; an active type has no "how it looks while inactive".
     */
    public function activate(): void
    {
        if ($this->isActive) {
            return;
        }

        $this->isActive = true;
        $this->inactiveDisplay = null;
        $this->markChanged('is_active');
        $this->markChanged('inactive_display');
    }

    /**
     * No longer offered to a new application, shown to one as staff choose: hidden or greyed out
     * (amendment 5). Choosing again while it is inactive changes only how it looks.
     */
    public function deactivate(InactiveTypeDisplay $shown): void
    {
        if ($this->isActive) {
            $this->isActive = false;
            $this->markChanged('is_active');
        }

        if ($this->inactiveDisplay === $shown) {
            return;
        }

        $this->inactiveDisplay = $shown;
        $this->markChanged('inactive_display');
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

    public function storeId(): string
    {
        return $this->storeId;
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

    /** Null while it is active. */
    public function inactiveDisplay(): ?InactiveTypeDisplay
    {
        return $this->inactiveDisplay;
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
