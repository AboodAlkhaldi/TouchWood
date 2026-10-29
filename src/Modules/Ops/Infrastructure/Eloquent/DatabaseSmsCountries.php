<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\SmsCountry;
use Modules\Ops\Domain\Repository\SmsCountries;
use stdClass;

/** `SmsCountries` in SQL (`ops.sms_countries`). */
final readonly class DatabaseSmsCountries implements SmsCountries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lock(): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['ops:sms_countries'], false);
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): SmsCountry => self::country($row),
            $this->db->table('ops.sms_countries')->orderBy('country_code')->get(['country_code', 'calling_code', 'provider'])->all(),
        ));
    }

    public function byCode(string $countryCode): ?SmsCountry
    {
        $row = $this->db->table('ops.sms_countries')->where('country_code', $countryCode)->first(['country_code', 'calling_code', 'provider']);

        return $row === null ? null : self::country($row);
    }

    public function byCallingCode(string $callingCode): ?SmsCountry
    {
        $row = $this->db->table('ops.sms_countries')->where('calling_code', $callingCode)->first(['country_code', 'calling_code', 'provider']);

        return $row === null ? null : self::country($row);
    }

    public function add(SmsCountry $country): void
    {
        $this->db->table('ops.sms_countries')->insert([
            'country_code' => $country->countryCode,
            'calling_code' => $country->callingCode(),
            'provider' => $country->provider(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(SmsCountry $country): void
    {
        $this->db->table('ops.sms_countries')->where('country_code', $country->countryCode)->update([
            'calling_code' => $country->callingCode(),
            'provider' => $country->provider(),
            'updated_at' => now(),
        ]);
    }

    public function remove(string $countryCode): void
    {
        $this->db->table('ops.sms_countries')->where('country_code', $countryCode)->delete();
    }

    private static function country(stdClass $row): SmsCountry
    {
        return SmsCountry::reconstitute((string) $row->country_code, (string) $row->calling_code, (string) $row->provider);
    }
}
