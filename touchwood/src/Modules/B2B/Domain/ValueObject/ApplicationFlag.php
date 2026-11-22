<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * One item of a rejected application that staff marked as sent wrong (b2b.md §1.2, amendment 4):
 * a field, or the file under one document type — exactly one of the two. The next draft cannot be
 * sent until a flagged field holds a different value and a flagged document a newly uploaded file.
 *
 * It belongs to the rejected application: it is part of what that application was told.
 */
final readonly class ApplicationFlag
{
    private function __construct(
        public ?FlaggedField $field,
        public ?string $documentTypeId,
    ) {}

    public static function field(FlaggedField $field): self
    {
        return new self($field, null);
    }

    public static function document(string $documentTypeId): self
    {
        return new self(null, strtolower($documentTypeId));
    }

    /**
     * What makes two flags the same flag: one per field, one per document type (b2b.md §5).
     */
    public function key(): string
    {
        return $this->field !== null ? 'field:'.$this->field->value : 'document:'.$this->documentTypeId;
    }
}
