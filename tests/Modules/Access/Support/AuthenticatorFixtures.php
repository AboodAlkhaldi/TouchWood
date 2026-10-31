<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\RecoveryCodes;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use PragmaRX\Google2FA\Google2FA;

/**
 * Staff authenticator apps in tests (access.md amendment 91): which sign-in methods are on, an app set
 * up without going through the pages, and the codes an app would show.
 */
final class AuthenticatorFixtures
{
    /**
     * The two methods, as a Super Admin would tick them (tests/Pest.php starts every Feature and Browser
     * test with SMS alone).
     */
    public static function methods(bool $sms, bool $authenticator): void
    {
        staffSignInMethodsForTests($sms, $authenticator);
    }

    /**
     * An app set up for the staff member, with ten recovery codes.
     *
     * @return array{secret: string, codes: list<string>}
     */
    public static function giveApp(string $staffId, bool $inUse = true): array
    {
        $repository = app(StaffAuthenticatorRepository::class);
        $secret = (new Google2FA)->generateSecretKey(32);
        $now = CarbonImmutable::now();

        $repository->startSetup($staffId, $secret, $now);
        // An earlier step than any code a test sends, so the first code is never "already used".
        $repository->confirmSetup($staffId, self::step() - 10, $now);
        $repository->setInUse($staffId, $inUse);

        $codes = RecoveryCodes::generate();
        $hashes = app(Codes::class);
        $repository->replaceRecoveryCodes($staffId, array_map(fn (string $code): string => $hashes->hash($staffId, RecoveryCodes::normalize($code)), $codes), $now);

        return ['secret' => $secret, 'codes' => $codes];
    }

    /**
     * The code an app shows now, or $steps of 30 seconds from now.
     */
    public static function code(string $secret, int $steps = 0): string
    {
        return (new Google2FA)->oathTotp($secret, self::step() + $steps);
    }

    /**
     * A code that is none of the ones accepted around now.
     */
    public static function wrongCode(string $secret): string
    {
        $near = [self::code($secret, -1), self::code($secret), self::code($secret, 1)];

        for ($n = 0; $n < 1000000; $n++) {
            $candidate = str_pad((string) $n, 6, '0', STR_PAD_LEFT);

            if (! in_array($candidate, $near, true)) {
                return $candidate;
            }
        }

        return '000000';
    }

    public static function step(): int
    {
        return intdiv(CarbonImmutable::now()->getTimestamp(), 30);
    }
}
