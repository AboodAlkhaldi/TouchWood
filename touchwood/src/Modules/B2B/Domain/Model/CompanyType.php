<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\InactiveTypeDisplay;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\B2B\Domain\ValueObject\TypePosition;

/**
 * A kind of company a customer can say they are — a limited liability company, a sole
 * proprietorship (b2b.md §1.3). A table staff manage, not a list in code, and **one list per store**
 * (amendment 5): a legal form in one country is not one in another, and a company uses its home
 * store's list. Every store starts with the same six (StartingTypes); "Other", which the company
 * describes in its own words, is not a row at all.
 *
 * Never deleted once used, only deactivated: the applications that name it are permanent. An
 * inactive type cannot be chosen by a new application, and a draft that chose it before must choose
 * again before it is sent (owner, 2026-09-27); a company already submitted keeps it. Deactivating,
 * staff choose how it looks to new applications: hidden, or greyed out (amendment 5).
 */
final class CompanyType
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
    ) {}

    /**
     * @param  string  $storeId  the store whose list it joins
     *
     * @throws InvalidCompanyAttribute
     */
    public static function add(string $id, string $storeId, TypeName $name, int $position): self
    {
        return new self($id, strtolower($storeId), $name, TypePosition::check($position), true, null);
    }

    public static function reconstitute(string $id, string $storeId, TypeName $name, int $position, bool $isActive, ?InactiveTypeDisplay $inactiveDisplay): self
    {
        return new self($id, $storeId, $name, $position, $isActive, $inactiveDisplay);
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
     * No longer chosen by a new application, shown to one as staff choose: hidden or greyed out
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
