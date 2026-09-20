<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\CustomerPhoneCode;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use stdClass;

final readonly class DatabaseCustomerTokenRepository implements CustomerTokenRepository
{
    private const string PHONE_CODES = 'access.phone_codes';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function putPhoneCode(CustomerPhoneCode $code): void
    {
        $this->db->table(self::PHONE_CODES)->upsert([[
            'customer_id' => $code->customerId,
            'purpose' => $code->purpose->value,
            'phone' => $code->phone->value,
            'code_hash' => $code->codeHash,
            'attempts' => $code->attempts,
            'expires_at' => $code->expiresAt,
            'sent_at' => $code->sentAt,
        ]], ['customer_id'], ['purpose', 'phone', 'code_hash', 'attempts', 'expires_at', 'sent_at']);
    }

    public function phoneCode(string $customerId): ?CustomerPhoneCode
    {
        $row = $this->db->table(self::PHONE_CODES)->where('customer_id', $customerId)->lockForUpdate()->first();

        return $row instanceof stdClass ? new CustomerPhoneCode(
            (string) $row->customer_id,
            CustomerPhoneCodePurpose::from((string) $row->purpose),
            PhoneNumber::of((string) $row->phone),
            (string) $row->code_hash,
            (int) $row->attempts,
            CarbonImmutable::parse((string) $row->expires_at),
            CarbonImmutable::parse((string) $row->sent_at),
        ) : null;
    }

    public function countFailedAttempt(string $customerId): void
    {
        $this->db->table(self::PHONE_CODES)->where('customer_id', $customerId)->increment('attempts');
    }

    public function deletePhoneCode(string $customerId): void
    {
        $this->db->table(self::PHONE_CODES)->where('customer_id', $customerId)->delete();
    }
}
