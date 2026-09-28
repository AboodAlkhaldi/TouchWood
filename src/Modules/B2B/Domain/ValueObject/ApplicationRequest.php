<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * Something staff ask this one company for when rejecting its application (b2b.md §1.2,
 * amendment 4): a text answer or a file, under a label the staff member writes — "A bank letter
 * confirming the account". Every request must be answered before the next draft is sent.
 *
 * It belongs to the rejected application; its answers belong to the application that gives them.
 */
final readonly class ApplicationRequest
{
    /** The label is one line (b2b.md §5). */
    public const int LABEL_MAX = 200;

    private function __construct(
        public string $id,
        public RequestKind $kind,
        public string $label,
        public int $position,
    ) {}

    /**
     * @param  int  $position  its place in the draft's section of requests, as a type's on the form
     *
     * @throws InvalidCompanyAttribute
     */
    public static function add(string $id, RequestKind $kind, string $label, int $position): self
    {
        return new self($id, $kind, CompanyText::oneLine('label', $label, self::LABEL_MAX), TypePosition::check($position));
    }

    public static function reconstitute(string $id, RequestKind $kind, string $label, int $position): self
    {
        return new self($id, $kind, $label, $position);
    }
}
