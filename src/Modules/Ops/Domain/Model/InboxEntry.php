<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * One entry of a person's inbox, the bell (ops.md §1.2): a customer's or a staff member's, in one
 * store, its title and line in both languages, an optional link inside the site.
 *
 * Every rule of `ops.inbox_entries`' CHECKs is refused here first, with a clear error (conventions:
 * code catches mistakes before the database).
 */
final readonly class InboxEntry
{
    public const int TITLE_MAX = 200;

    public const int LINE_MAX = 500;

    public const int LINK_MAX = 2000;

    private function __construct(
        public string $id,
        public ?string $customerId,
        public ?string $staffId,
        public StoreId $store,
        public MessageKind $kind,
        public string $titleAr,
        public string $titleEn,
        public string $lineAr,
        public string $lineEn,
        public ?string $link,
        public DateTimeImmutable $createdAt,
    ) {}

    /** @throws InvalidOpsInput */
    public static function forCustomer(string $id, string $customerId, StoreId $store, MessageKind $kind, string $titleAr, string $titleEn, string $lineAr, string $lineEn, ?string $link, DateTimeImmutable $createdAt): self
    {
        if ($kind->isForStaff()) {
            throw new InvalidOpsInput('kind', 'a staff alert in a customer\'s inbox');
        }

        return self::checked($id, strtolower($customerId), null, $store, $kind, $titleAr, $titleEn, $lineAr, $lineEn, $link, $createdAt);
    }

    /** @throws InvalidOpsInput */
    public static function forStaff(string $id, string $staffId, StoreId $store, MessageKind $kind, string $titleAr, string $titleEn, string $lineAr, string $lineEn, ?string $link, DateTimeImmutable $createdAt): self
    {
        if (! $kind->isForStaff()) {
            throw new InvalidOpsInput('kind', 'a customer\'s message in a staff inbox');
        }

        return self::checked($id, null, strtolower($staffId), $store, $kind, $titleAr, $titleEn, $lineAr, $lineEn, $link, $createdAt);
    }

    /** @throws InvalidOpsInput */
    private static function checked(string $id, ?string $customerId, ?string $staffId, StoreId $store, MessageKind $kind, string $titleAr, string $titleEn, string $lineAr, string $lineEn, ?string $link, DateTimeImmutable $createdAt): self
    {
        if (! $kind->hasBell()) {
            throw new InvalidOpsInput('kind', 'this kind makes no bell entry');
        }

        foreach (['title_ar' => [$titleAr, self::TITLE_MAX], 'title_en' => [$titleEn, self::TITLE_MAX], 'line_ar' => [$lineAr, self::LINE_MAX], 'line_en' => [$lineEn, self::LINE_MAX]] as $field => [$text, $max]) {
            $length = mb_strlen($text);

            if ($length < 1 || $length > $max || trim($text) === '') {
                throw new InvalidOpsInput($field, "between 1 and {$max} characters");
            }
        }

        if ($link !== null && (mb_strlen($link) < 1 || mb_strlen($link) > self::LINK_MAX || ! str_starts_with($link, '/') || str_starts_with($link, '//'))) {
            throw new InvalidOpsInput('link', 'a path inside the site');
        }

        return new self(strtolower($id), $customerId, $staffId, $store, $kind, $titleAr, $titleEn, $lineAr, $lineEn, $link, $createdAt);
    }
}
