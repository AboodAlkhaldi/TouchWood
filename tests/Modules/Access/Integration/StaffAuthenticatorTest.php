<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Security\AuthenticatorCodes;
use Modules\Access\Application\Security\QrCodes;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\ValueObject\SignInMethod;
use PragmaRX\Google2FA\Google2FA;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AuthenticatorFixtures as App;

use function Pest\Laravel\seed;

/*
| The authenticator app's parts (access.md amendment 91): the codes as every app reads them, a code
| never taken twice, which method a person uses, the QR code drawn here, and the tables' last line of
| defence.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

describe('app codes', function () {
    it('takes the code of now and of one step either side, and answers with its step', function () {
        $codes = app(AuthenticatorCodes::class);
        $secret = $codes->newSecret();
        $now = CarbonImmutable::parse('2026-10-31 12:00:10 UTC');
        $step = intdiv($now->getTimestamp(), 30);
        $totp = new Google2FA;

        expect(strlen($secret))->toBe(32)
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step), null, $now))->toBe($step)
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step - 1), null, $now))->toBe($step - 1)
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step + 1), null, $now))->toBe($step + 1)
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step - 2), null, $now))->toBeNull()
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step + 2), null, $now))->toBeNull();
    });

    it('refuses a code of the step already used, or of an earlier one', function () {
        $codes = app(AuthenticatorCodes::class);
        $secret = $codes->newSecret();
        $now = CarbonImmutable::parse('2026-10-31 12:00:10 UTC');
        $step = intdiv($now->getTimestamp(), 30);
        $totp = new Google2FA;

        expect($codes->verify($secret, $totp->oathTotp($secret, $step), $step, $now))->toBeNull()
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step - 1), $step, $now))->toBeNull()
            ->and($codes->verify($secret, $totp->oathTotp($secret, $step + 1), $step, $now))->toBe($step + 1);
    });

    it('refuses anything but six digits', function () {
        $codes = app(AuthenticatorCodes::class);
        $secret = $codes->newSecret();

        expect($codes->verify($secret, '12345', null, CarbonImmutable::now()))->toBeNull()
            ->and($codes->verify($secret, 'abcdef', null, CarbonImmutable::now()))->toBeNull()
            ->and($codes->verify($secret, '1234567', null, CarbonImmutable::now()))->toBeNull();
    });

    it('names the panel and the person in the QR code, and draws it here as SVG', function () {
        $codes = app(AuthenticatorCodes::class);
        $uri = $codes->uri('someone@example.test', 'JBSWY3DPEHPK3PXP');

        expect($uri)->toStartWith('otpauth://totp/')
            ->toContain('someone%40example.test')
            ->toContain('secret=JBSWY3DPEHPK3PXP')
            ->toContain('issuer='.rawurlencode((string) config('app.name')))
            ->and(app(QrCodes::class)->svg($uri))->toContain('<svg');
    });
});

describe('which method a person uses (decisions 1 and 3)', function () {
    it('uses the one box ticked, for everyone', function () {
        $staffId = Fx::staff();
        App::giveApp($staffId);
        $methods = app(StaffSignInMethods::class);

        App::methods(sms: true, authenticator: false);
        expect($methods->for($staffId))->toBe(SignInMethod::Sms);

        App::methods(sms: false, authenticator: true);
        expect($methods->for($staffId))->toBe(SignInMethod::Authenticator)
            ->and($methods->for(Fx::staff()))->toBe(SignInMethod::Authenticator);
    });

    it('with both ticked, uses the app of whoever set one up and kept it, and SMS for the rest', function () {
        App::methods(sms: true, authenticator: true);
        $withApp = Fx::staff();
        App::giveApp($withApp);
        $choseSms = Fx::staff();
        App::giveApp($choseSms, inUse: false);
        $methods = app(StaffSignInMethods::class);

        expect($methods->for($withApp))->toBe(SignInMethod::Authenticator)
            ->and($methods->for($choseSms))->toBe(SignInMethod::Sms)
            ->and($methods->for(Fx::staff()))->toBe(SignInMethod::Sms);
    });

    it('asks for a setup exactly when the app is the method and none is set up', function () {
        $methods = app(StaffSignInMethods::class);
        $without = Fx::staff();
        $with = Fx::staff();
        App::giveApp($with);

        App::methods(sms: false, authenticator: true);
        expect($methods->mustSetUp($without))->toBeTrue()->and($methods->mustSetUp($with))->toBeFalse();

        App::methods(sms: true, authenticator: true);
        expect($methods->mustSetUp($without))->toBeFalse();

        App::methods(sms: true, authenticator: false);
        expect($methods->mustSetUp($without))->toBeFalse();
    });

    it('falls back to the go-live default, never to nobody, were both ever stored off', function () {
        App::methods(sms: false, authenticator: false);

        expect(app(StaffSignInMethods::class)->enabled())->toBe(['sms' => false, 'authenticator' => true]);
    });
});

it('answers whether an app is set up, and used, without decrypting it', function () {
    $repository = app(StaffAuthenticatorRepository::class);
    $waiting = Fx::staff();
    $repository->startSetup($waiting, 'JBSWY3DPEHPK3PXP', CarbonImmutable::now());
    $setAside = Fx::staff();
    App::giveApp($setAside, inUse: false);
    $using = Fx::staff();
    App::giveApp($using);
    // A secret the key cannot open: a read that decrypted would fail.
    DB::table('access.staff_authenticators')->whereIn('staff_user_id', [$setAside, $using])->update(['secret' => 'not encrypted']);

    expect($repository->hasConfirmedApp($waiting))->toBeFalse()
        ->and($repository->hasConfirmedApp($setAside))->toBeTrue()
        ->and($repository->hasConfirmedApp($using, lock: true))->toBeTrue()
        ->and($repository->usesApp($setAside))->toBeFalse()
        ->and($repository->usesApp($using))->toBeTrue()
        ->and($repository->hasConfirmedApp(Fx::staff()))->toBeFalse();
});

describe('the tables (amendment 91)', function () {
    it('creates them', function (string $table) {
        expect(DB::table('information_schema.tables')->where('table_schema', 'access')->where('table_name', $table)->exists())->toBeTrue();
    })->with(['staff_authenticators', 'staff_recovery_codes']);

    it('refuses rows that break the rules, even when they skip the code', function (Closure $insert, string $constraint) {
        expect($insert)->toThrow(QueryException::class, $constraint);
    })->with([
        'a secret without when it was confirmed' => [fn () => DB::table('access.staff_authenticators')->insert([
            'staff_user_id' => Fx::staff(), 'secret' => 'x', 'confirmed_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]), 'staff_authenticators_confirmed'],
        'a waiting setup without when it started' => [fn () => DB::table('access.staff_authenticators')->insert([
            'staff_user_id' => Fx::staff(), 'pending_secret' => 'x', 'pending_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]), 'staff_authenticators_pending'],
        'a row holding nothing' => [fn () => DB::table('access.staff_authenticators')->insert([
            'staff_user_id' => Fx::staff(), 'created_at' => now(), 'updated_at' => now(),
        ]), 'staff_authenticators_something'],
        'an app for nobody' => [fn () => DB::table('access.staff_authenticators')->insert([
            'staff_user_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3', 'pending_secret' => 'x', 'pending_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]), 'staff_authenticators_staff_user_id_foreign'],
        'one recovery code twice' => [function () {
            $staffId = Fx::staff();

            foreach ([1, 2] as $n) {
                DB::table('access.staff_recovery_codes')->insert(['staff_user_id' => $staffId, 'code_hash' => hash('sha256', 'c'), 'created_at' => now()]);
            }
        }, 'staff_recovery_codes_pkey'],
    ]);
});
