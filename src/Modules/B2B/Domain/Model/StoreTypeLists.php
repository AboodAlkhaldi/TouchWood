<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Model;

/**
 * What B2B knows about one store's two type lists as a whole (b2b.md amendment 6(a)): whether they
 * are still **the starting lists, copied in and not yet reviewed** by that store's admins.
 *
 * Every store starts with the same company types and document types (StartingTypes), written in
 * when its lists are empty — when the schema is migrated, and when a store is opened later. They
 * are one country's forms and papers, so the store's types page tells its admins the lists were
 * copied, until one of them changes a type or marks the lists reviewed. One flag covers both lists.
 *
 * It only ever clears: nothing sets it again once staff have seen the lists.
 */
final class StoreTypeLists
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $storeId,
        private bool $copiedNotReviewed,
    ) {}

    /**
     * A store whose lists were just written from the starting ones.
     */
    public static function copied(string $storeId): self
    {
        return new self(strtolower($storeId), true);
    }

    public static function reconstitute(string $storeId, bool $copiedNotReviewed): self
    {
        return new self($storeId, $copiedNotReviewed);
    }

    /**
     * The store's admins have seen the lists — they changed a type, or said so.
     */
    public function markReviewed(): void
    {
        if (! $this->copiedNotReviewed) {
            return;
        }

        $this->copiedNotReviewed = false;
        $this->markChanged('copied_not_reviewed');
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    /** Whether the types page still says the lists were copied in. */
    public function copiedNotReviewed(): bool
    {
        return $this->copiedNotReviewed;
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
