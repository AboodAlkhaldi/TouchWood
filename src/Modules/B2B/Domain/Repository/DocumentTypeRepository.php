<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\TypeName;

/**
 * The document types staff manage (b2b.md §1.3). Listed by position, then by English name: two
 * types may share a position.
 */
interface DocumentTypeRepository
{
    public function nextId(): string;

    /** A read with no lock. */
    public function find(string $typeId): ?DocumentType;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $typeId): ?DocumentType;

    /**
     * Every type, active or not — the staff screen.
     *
     * @return list<DocumentType>
     */
    public function all(): array;

    /**
     * The types the form offers: every active one, required ones marked (b2b.md §1.2).
     *
     * @return list<DocumentType>
     */
    public function active(): array;

    /**
     * Whether another type already has this name in either language, ignoring case (owner,
     * 2026-09-27). $exceptId is the type being renamed, which may keep its own name.
     */
    public function nameTaken(TypeName $name, ?string $exceptId = null): bool;

    public function add(DocumentType $type): void;

    public function update(DocumentType $type): void;
}
