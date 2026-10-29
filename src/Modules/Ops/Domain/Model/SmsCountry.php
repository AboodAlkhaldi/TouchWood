<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use Locale;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Domain\Text\LatinDigits;

/**
 * A country whose phones SMS reaches (owner, 2026-10-29; access.md amendment 87): its ISO code, its
 * calling code as a Super Admin typed it - digits only, such as 966 - and the provider that sends to
 * it. Removed, never retired: a phone of a country taken off the list is refused from then on.
 *
 * Every rule of `ops.sms_countries`' CHECKs is refused here first. Whether the provider is one Ops
 * has is the handler's to check: the providers are code, not data.
 */
final class SmsCountry
{
    private const string CALLING_CODE = '/\A[1-9]\d{0,2}\z/';

    private const string PROVIDER = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        public readonly string $countryCode,
        private string $callingCode,
        private string $provider,
    ) {}

    /** @throws InvalidOpsInput */
    public static function add(string $countryCode, string $callingCode, string $provider): self
    {
        $code = self::countryCode($countryCode);

        return new self($code, self::callingCodeOf($callingCode), self::providerOf($provider));
    }

    public static function reconstitute(string $countryCode, string $callingCode, string $provider): self
    {
        return new self($countryCode, $callingCode, $provider);
    }

    /**
     * The code as a person typed it, in capitals; refused unless PHP knows the country by it - it is
     * where the list's names come from (owner, 2026-10-29).
     *
     * @throws InvalidOpsInput
     */
    public static function countryCode(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        $name = preg_match('/\A[A-Z]{2}\z/', $code) === 1 ? Locale::getDisplayRegion('-'.$code, 'en') : false;

        // ICU answers an unknown region with its own code, and names ZZ "Unknown Region".
        if ($name === false || $name === '' || $name === $code || $code === 'ZZ') {
            throw new InvalidOpsInput('country_code', 'expected an ISO 3166-1 country code');
        }

        return $code;
    }

    public function callingCode(): string
    {
        return $this->callingCode;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** @throws InvalidOpsInput */
    public function changeCallingCode(string $callingCode): void
    {
        $callingCode = self::callingCodeOf($callingCode);

        if ($callingCode !== $this->callingCode) {
            $this->callingCode = $callingCode;
            $this->changed[] = 'calling_code';
        }
    }

    /** @throws InvalidOpsInput */
    public function changeProvider(string $provider): void
    {
        $provider = self::providerOf($provider);

        if ($provider !== $this->provider) {
            $this->provider = $provider;
            $this->changed[] = 'provider';
        }
    }

    /**
     * Whether an E.164 number (+9665…) begins with this country's calling code.
     */
    public function serves(string $phone): bool
    {
        return str_starts_with($phone, '+'.$this->callingCode);
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        $changed = $this->changed;
        $this->changed = [];

        return $changed;
    }

    /**
     * Digits only, one to three, never starting with 0 (ITU). A digit typed on an Arabic keyboard is
     * the same digit (frontend.md §1.8). Nothing else is guessed: no typo guard beyond the format
     * (owner, 2026-10-29).
     *
     * @throws InvalidOpsInput
     */
    private static function callingCodeOf(string $callingCode): string
    {
        $digits = LatinDigits::of(trim($callingCode));

        if (preg_match(self::CALLING_CODE, $digits) !== 1) {
            throw new InvalidOpsInput('calling_code', 'one to three digits, not starting with 0');
        }

        return $digits;
    }

    /** @throws InvalidOpsInput */
    private static function providerOf(string $provider): string
    {
        $provider = trim($provider);

        if (preg_match(self::PROVIDER, $provider) !== 1) {
            throw new InvalidOpsInput('provider', 'a provider\'s name');
        }

        return $provider;
    }
}
