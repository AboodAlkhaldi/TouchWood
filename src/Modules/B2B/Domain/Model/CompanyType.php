<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\TypeName;
use Modules\B2B\Domain\ValueObject\TypePosition;

/**
 * A kind of company a customer can say they are — a limited liability company, a sole
 * proprietorship (b2b.md §1.3). A table staff manage, not a list in code; six ship with the
 * migration (owner, 2026-09-27), and "Other", which the company describes in its own words, is not
 * a row at all.
 *
 * Never deleted once used, only deactivated: the applications that name it are permanent. An
 * inactive type cannot be chosen by a new application, and a draft that chose it before must choose
 * again before it is sent (owner, 2026-09-27); a company already submitted keeps it.
 */
final class CompanyType
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private TypeName $name,
        private int $position,
        private bool $isActive,
    ) {}

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function add(string $id, TypeName $name, int $position): self
    {
        return new self($id, $name, TypePosition::check($position), true);
    }

    public static function reconstitute(string $id, TypeName $name, int $position, bool $isActive): self
    {
        return new self($id, $name, $position, $isActive);
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
