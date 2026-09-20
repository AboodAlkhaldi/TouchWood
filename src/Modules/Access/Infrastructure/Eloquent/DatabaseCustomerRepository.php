<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\EmailAlreadyRegistered;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Model\Customer;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use stdClass;

final readonly class DatabaseCustomerRepository implements CustomerRepository
{
    private const string TABLE = 'access.customers';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function byId(string $id): ?Customer
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toCustomer($row) : null;
    }

    public function find(string $id): ?Customer
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($id))->first();

        return $row instanceof stdClass ? $this->toCustomer($row) : null;
    }

    public function byEmail(EmailAddress $email): ?Customer
    {
        $row = $this->db->table(self::TABLE)
            ->whereRaw('lower(email) = lower(?)', [$email->value])
            ->lockForUpdate()
            ->first();

        return $row instanceof stdClass ? $this->toCustomer($row) : null;
    }

    public function emailInUse(EmailAddress $email): bool
    {
        return $this->db->table(self::TABLE)
            ->whereRaw('lower(email) = lower(?)', [$email->value])
            ->exists();
    }

    public function phoneInUse(PhoneNumber $phone, ?string $exceptCustomerId = null): bool
    {
        return $this->db->table(self::TABLE)
            ->where('phone', $phone->value)
            ->when($exceptCustomerId !== null, fn ($query) => $query->where('id', '<>', $exceptCustomerId))
            ->exists();
    }

    public function add(Customer $customer): void
    {
        $this->write(fn () => $this->db->table(self::TABLE)->insert([
            'id' => $customer->id(),
            ...$this->attributes($customer),
            // Set once and never written again: a trigger refuses a change (spec §5.1).
            'account_type' => $customer->accountType()->value,
            'home_store_id' => $customer->homeStoreId(),
            'terms_version' => $customer->termsVersion(),
            'terms_accepted_at' => $customer->termsAcceptedAt(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]));
    }

    public function update(Customer $customer): void
    {
        $this->write(fn () => $this->db->table(self::TABLE)->where('id', $customer->id())->update([
            ...$this->attributes($customer),
            'updated_at' => CarbonImmutable::now(),
        ]));
    }

    /**
     * The code checks the email and the phone first; the unique indexes catch someone who took the
     * value in between, and each becomes the same error the code would have raised.
     */
    private function write(Closure $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            throw match (true) {
                str_contains($e->getMessage(), 'customers_email_unique') => new EmailAlreadyRegistered,
                str_contains($e->getMessage(), 'customers_phone_unique') => new PhoneAlreadyInUse,
                default => $e,
            };
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Customer $customer): array
    {
        return [
            'email' => $customer->email()->value,
            'password' => $customer->passwordHash(),
            'first_name' => $customer->firstName(),
            'last_name' => $customer->lastName(),
            'status' => $customer->status()->value,
            'email_verified_at' => $customer->emailVerifiedAt(),
            'phone' => $customer->phone()?->value,
            'phone_verified_at' => $customer->phoneVerifiedAt(),
            'locale' => $customer->language()->value,
            'last_store_id' => $customer->lastStoreId(),
            'session_version' => $customer->sessionVersion(),
        ];
    }

    private function toCustomer(stdClass $row): Customer
    {
        return Customer::reconstitute(
            (string) $row->id,
            EmailAddress::of((string) $row->email),
            (string) $row->password,
            (string) $row->first_name,
            (string) $row->last_name,
            AccountType::from((string) $row->account_type),
            CustomerStatus::from((string) $row->status),
            $row->email_verified_at === null ? null : CarbonImmutable::parse((string) $row->email_verified_at),
            $row->phone === null ? null : PhoneNumber::of((string) $row->phone),
            $row->phone_verified_at === null ? null : CarbonImmutable::parse((string) $row->phone_verified_at),
            Language::from((string) $row->locale),
            (string) $row->home_store_id,
            (string) $row->last_store_id,
            (string) $row->terms_version,
            CarbonImmutable::parse((string) $row->terms_accepted_at),
            $row->deletion_scheduled_for === null ? null : CarbonImmutable::parse((string) $row->deletion_scheduled_for),
            (int) $row->session_version,
        );
    }
}
