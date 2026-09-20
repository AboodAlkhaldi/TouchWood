<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\ValueObject\MapPin;
use Modules\Access\Domain\ValueObject\PhoneNumber;

/**
 * Where a customer's order goes (spec §1.9). An address belongs to one customer and one store — the
 * store of the country it is in — and never moves to another: an address in one country cannot
 * become an address in another by being edited. Its values are checked against that store's format
 * before they are ever set (StoreAddressFormat).
 *
 * Every field is personal data: the audit log keeps only that it changed (spec §3.3).
 */
final class Address
{
    private const int LABEL_MAX = 50;

    private const int RECIPIENT_MAX = 100;

    /** @var list<string> */
    private array $changed = [];

    /**
     * @param  array<string, string>  $fields  the store format's values, already accepted by it
     */
    private function __construct(
        private readonly string $id,
        private readonly string $customerId,
        private readonly string $storeId,
        private string $label,
        private string $recipientName,
        private PhoneNumber $phone,
        private array $fields,
        private ?MapPin $pin,
        private bool $isDefault,
    ) {}

    /**
     * @param  array<string, string>  $fields
     *
     * @throws InvalidAddress
     */
    public static function add(
        string $id,
        string $customerId,
        string $storeId,
        string $label,
        string $recipientName,
        PhoneNumber $phone,
        array $fields,
        ?MapPin $pin,
        bool $isDefault,
    ): self {
        return new self(
            $id, $customerId, $storeId,
            self::text('label', $label, self::LABEL_MAX),
            self::text('recipient_name', $recipientName, self::RECIPIENT_MAX),
            $phone, $fields, $pin, $isDefault,
        );
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function reconstitute(
        string $id,
        string $customerId,
        string $storeId,
        string $label,
        string $recipientName,
        PhoneNumber $phone,
        array $fields,
        ?MapPin $pin,
        bool $isDefault,
    ): self {
        return new self($id, $customerId, $storeId, $label, $recipientName, $phone, $fields, $pin, $isDefault);
    }

    /**
     * Everything the customer may change. The store never changes: an address in another country is
     * a new address there (spec §1.9).
     *
     * @param  array<string, string>  $fields  already accepted by the store's format
     *
     * @throws InvalidAddress
     */
    public function change(string $label, string $recipientName, PhoneNumber $phone, array $fields, ?MapPin $pin): void
    {
        $label = self::text('label', $label, self::LABEL_MAX);
        $recipientName = self::text('recipient_name', $recipientName, self::RECIPIENT_MAX);

        if ($label !== $this->label) {
            $this->label = $label;
            $this->markChanged('label');
        }

        if ($recipientName !== $this->recipientName) {
            $this->recipientName = $recipientName;
            $this->markChanged('recipient_name');
        }

        if ($phone->value !== $this->phone->value) {
            $this->phone = $phone;
            $this->markChanged('phone');
        }

        if ($fields !== $this->fields) {
            $this->fields = $fields;
            $this->markChanged('fields');
        }

        if (! ($pin?->equals($this->pin) ?? $this->pin === null)) {
            $this->pin = $pin;
            $this->markChanged('map_pin');
        }
    }

    public function makeDefault(): void
    {
        if ($this->isDefault) {
            return;
        }

        $this->isDefault = true;
        $this->markChanged('is_default');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function recipientName(): string
    {
        return $this->recipientName;
    }

    public function phone(): PhoneNumber
    {
        return $this->phone;
    }

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function pin(): ?MapPin
    {
        return $this->pin;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function belongsTo(string $customerId): bool
    {
        return $this->customerId === $customerId;
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

    /**
     * @throws InvalidAddress
     */
    private static function text(string $attribute, string $value, int $max): string
    {
        $text = trim($value);

        return match (true) {
            $text === '' => throw new InvalidAddress($attribute, 'required'),
            preg_match('//u', $text) !== 1 => throw new InvalidAddress($attribute, 'text'),
            // One line, like every value of an address: a label prints what it is given.
            preg_match('/\p{Cc}/u', $text) === 1 => throw new InvalidAddress($attribute, 'on one line, without control characters'),
            mb_strlen($text) > $max => throw new InvalidAddress($attribute, "at most {$max} characters"),
            default => $text,
        };
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}
