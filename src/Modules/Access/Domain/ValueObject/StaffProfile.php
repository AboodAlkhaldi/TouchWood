<?php

declare(strict_types=1);

namespace Modules\Access\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;

/**
 * A staff member's profile (handoff §7.6). Everything but the address is required at invitation
 * (owner's decision, 2026-09-19). Names and the address are personal data: the audit log records
 * only that they changed.
 */
final readonly class StaffProfile
{
    private const int MAX_TEXT = 100;

    private const int MAX_ADDRESS = 500;

    private const string EARLIEST_BIRTH = '1900-01-01';

    private function __construct(
        public string $firstName,
        public string $lastName,
        public string $jobTitle,
        public DateTimeImmutable $dateOfBirth,
        public CountryCode $country,
        public ?string $address,
    ) {}

    /**
     * @param  string  $dateOfBirth  YYYY-MM-DD
     */
    public static function of(
        string $firstName,
        string $lastName,
        string $jobTitle,
        string $dateOfBirth,
        string $country,
        ?string $address,
        ?DateTimeImmutable $today = null,
    ): self {
        return new self(
            self::text('first_name', $firstName),
            self::text('last_name', $lastName),
            self::text('job_title', $jobTitle),
            self::birthDate($dateOfBirth, $today ?? new DateTimeImmutable('today')),
            CountryCode::of($country),
            self::address($address),
        );
    }

    /**
     * Rebuilds a stored profile: its values were checked when written, and "in the past" is about
     * the day it was entered.
     */
    public static function reconstitute(string $firstName, string $lastName, string $jobTitle, DateTimeImmutable $dateOfBirth, CountryCode $country, ?string $address): self
    {
        return new self($firstName, $lastName, $jobTitle, $dateOfBirth, $country, $address);
    }

    public function equals(self $other): bool
    {
        return $this->firstName === $other->firstName
            && $this->lastName === $other->lastName
            && $this->jobTitle === $other->jobTitle
            && $this->dateOfBirth->format('Y-m-d') === $other->dateOfBirth->format('Y-m-d')
            && $this->country->value === $other->country->value
            && $this->address === $other->address;
    }

    private static function text(string $attribute, string $value): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_TEXT) {
            throw new InvalidAccessAttribute($attribute, 'required, at most '.self::MAX_TEXT.' characters');
        }

        return $value;
    }

    private static function birthDate(string $value, DateTimeImmutable $today): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)
            || $date <= new DateTimeImmutable(self::EARLIEST_BIRTH) || $date >= $today) {
            throw new InvalidAccessAttribute('date_of_birth', 'a date (YYYY-MM-DD) in the past, after 1900');
        }

        return $date;
    }

    private static function address(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === '' || $value === null) {
            return null;
        }

        if (mb_strlen($value) > self::MAX_ADDRESS) {
            throw new InvalidAccessAttribute('address', 'at most '.self::MAX_ADDRESS.' characters');
        }

        return $value;
    }
}
