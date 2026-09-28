<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\TypeName;

/**
 * The document types staff manage (b2b.md §1.3), one list per store (amendment 5). Listed by
 * position, then by English name: two types may share a position.
 */
interface DocumentTypeRepository
{
    public function nextId(): string;

    /** A read with no lock. */
    public function find(string $typeId): ?DocumentType;

    /** Locks the row: for a change, inside its transaction. */
    public function byId(string $typeId): ?DocumentType;

    /**
     * Every type of one store, active or not — the staff screen, and what a draft of a company from
     * that home store is checked against when it is sent.
     *
     * @return list<DocumentType>
     */
    public function all(string $storeId): array;

    /**
     * The types of one store the form offers: every active one, required ones marked (b2b.md §1.2).
     *
     * @return list<DocumentType>
     */
    public function active(string $storeId): array;

    /**
     * Whether another type of that store already has this name in either language, ignoring case
     * (owner, 2026-09-27; amendment 5). $exceptId is the type being renamed, which may keep its own
     * name. Two stores may share a name.
     */
    public function nameTaken(string $storeId, TypeName $name, ?string $exceptId = null): bool;

    public function add(DocumentType $type): void;

    public function update(DocumentType $type): void;
}
