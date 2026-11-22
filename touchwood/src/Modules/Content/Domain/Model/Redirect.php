<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

use DateTimeImmutable;

/**
 * A line of the redirects (content.md §1.15, §5 `content.redirects`): an old site's address and the shop page it
 * now goes to, with its visits, and what Check Addresses last found at the new address.
 */
final readonly class Redirect
{
    /** Typed in by hand (W11's Add Line). */
    public const string HAND = 'HAND';

    /** Written by Apply Sheet. */
    public const string SHEET = 'SHEET';

    /** Check Addresses: the new address answers its page. */
    public const string FINE = 'FINE';

    /** Check Addresses: the new address forwards elsewhere (a second hop), to `checkFinalPath`. */
    public const string FORWARDS = 'FORWARDS';

    /** Check Addresses: the new address answers not found. */
    public const string NOT_FOUND = 'NOT_FOUND';

    /** Check Addresses: the new address's store is off. */
    public const string STORE_OFF = 'STORE_OFF';

    /** On reading the list: a store's code now takes the old address's first word, so it is never asked. */
    public const string CANT_BE_REACHED = 'CANT_BE_REACHED';

    /** @var list<string> the marks Check Addresses writes */
    public const array CHECKS = [self::FINE, self::FORWARDS, self::NOT_FOUND, self::STORE_OFF];

    public function __construct(
        public string $oldPath,
        public string $newPath,
        public int $visits,
        public ?DateTimeImmutable $lastVisitAt,
        public string $source,
        public ?string $checkResult,
        public ?string $checkFinalPath,
        public ?DateTimeImmutable $checkedAt,
        public ?string $createdBy,
    ) {}

    /** The same line going to another address: what was checked of the old one no longer holds. */
    public function goingTo(string $newPath): self
    {
        return new self($this->oldPath, $newPath, $this->visits, $this->lastVisitAt, $this->source, null, null, null, $this->createdBy);
    }

    /**
     * Its short fields, for the audit (content.md §1.1 rule 13).
     *
     * @return array<string, string|int|null>
     */
    public function fields(): array
    {
        return ['old_path' => $this->oldPath, 'new_path' => $this->newPath];
    }
}
