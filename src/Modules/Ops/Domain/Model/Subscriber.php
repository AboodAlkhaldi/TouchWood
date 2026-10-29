<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Domain\ValueObject\StoreId;

/**
 * One email on a store's newsletter list (ops.md §1.9): a guest who gave it in the shop's box - on the
 * list once they confirm it (double opt-in) - or a customer who agreed to offers and news (§1.3), on
 * their home store's list only.
 *
 * `brevoEmail` and `brevoLocale` are what the newsletter provider holds for it, null while it holds
 * nothing: a change of email or language is a move, and a removal knows what to take out.
 */
final readonly class Subscriber
{
    public const int EMAIL_MAX = 254;

    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        public StoreId $store,
        public string $email,
        public string $locale,
        public ?string $customerId,
        public ?string $tokenHash,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $confirmedAt,
        public ?string $brevoEmail = null,
        public ?string $brevoLocale = null,
    ) {
        if ($email !== self::email($email)) {
            throw new InvalidOpsInput('email', 'an email address, in small letters');
        }

        if (! in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidOpsInput('locale', 'Arabic or English');
        }

        if ($customerId !== null && ($tokenHash !== null || $confirmedAt === null)) {
            throw new InvalidOpsInput('kind', 'a customer is on the list from the moment they agree');
        }

        if ($tokenHash !== null && ($confirmedAt !== null || preg_match('/\A[0-9a-f]{64}\z/', $tokenHash) !== 1)) {
            throw new InvalidOpsInput('token', 'the hash of a confirmation still waiting');
        }
    }

    /**
     * An email address as the list keeps it: trimmed, in small letters.
     *
     * @throws InvalidOpsInput
     */
    public static function email(string $email): string
    {
        $email = mb_strtolower(trim($email));

        if (mb_strlen($email) < 3 || mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidOpsInput('email', 'an email address');
        }

        return $email;
    }

    public function isGuest(): bool
    {
        return $this->customerId === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }

    /** What the provider should hold: the email in the list of its language, once confirmed; else nothing. */
    public function wantedAtProvider(): ?string
    {
        return $this->isConfirmed() ? $this->email.'|'.$this->locale : null;
    }

    /** What the provider holds now. */
    public function heldAtProvider(): ?string
    {
        return $this->brevoEmail === null || $this->brevoLocale === null ? null : $this->brevoEmail.'|'.$this->brevoLocale;
    }

    public function syncedAs(?string $email, ?string $locale): self
    {
        return new self($this->id, $this->store, $this->email, $this->locale, $this->customerId, $this->tokenHash, $this->createdAt, $this->confirmedAt, $email, $email === null ? null : $locale);
    }
}
