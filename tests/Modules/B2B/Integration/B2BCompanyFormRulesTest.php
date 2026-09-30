<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequest;
use Modules\B2B\Application\Command\AnswerApplicationRequest\AnswerApplicationRequestHandler;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocument;
use Modules\B2B\Application\Command\AttachApplicationDocument\AttachApplicationDocumentHandler;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraft;
use Modules\B2B\Application\Command\SaveApplicationDraft\SaveApplicationDraftHandler;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraft;
use Modules\B2B\Application\Command\StartApplicationDraft\StartApplicationDraftHandler;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplication;
use Modules\B2B\Application\Command\SubmitApplication\SubmitApplicationHandler;
use Modules\B2B\Application\Settings\FormRules;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\DuplicateDocumentFile;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyRepository;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| B2B step 6, after the owner used the page (b2b.md §4.5, amendment 16): what the company form holds
| a value to — the minimums, settings every store shares, when it is saved and again when it is
| sent; the same file in two sections; and the address, picked from the account's saved addresses.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

/** A minimum as an admin sets it on the settings page, one for every store. */
function formRulesMinimum(string $key, mixed $value): void
{
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, null, $value)));
}

/**
 * @param  array<string, string|null>  $fields
 */
function formRulesSave(array $fields): void
{
    app(SaveApplicationDraftHandler::class)->handle(new SaveApplicationDraft($fields));
}

/** A company account, email confirmed, acting, with a draft open. */
function formRulesDraft(): string
{
    $customerId = B2BFixtures::verifiedCompanyAccount();
    Fx::actAsCustomer($customerId);
    app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);

    return $customerId;
}

function formRulesOpen(string $customerId): Application
{
    return app(ApplicationRepository::class)->openFor($customerId) ?? throw new LogicException('No open application.');
}

function formRulesAttach(string $documentTypeId, string $filename): void
{
    app(AttachApplicationDocumentHandler::class)->handle(new AttachApplicationDocument($documentTypeId, B2BFixtures::pdf(), $filename));
}

/**
 * The refusal the work ends in, or null when it goes through.
 */
function formRulesRefusal(Closure $work): ?InvalidCompanyAttribute
{
    try {
        $work();
    } catch (InvalidCompanyAttribute $refused) {
        return $refused;
    }

    return null;
}

/**
 * @return list<string> the home store's active document type ids
 */
function formRulesDocumentTypeIds(): array
{
    return array_values(array_map(
        static fn ($type): string => $type->id(),
        array_filter(B2BFixtures::documentTypes(), static fn ($type): bool => $type->isActive()),
    ));
}

describe('the minimums (amendment 16(b))', function () {
    it('declares five, one set for every store, changed under the settings permission, each from 1 to its field\'s maximum', function () {
        $expected = [
            FormRules::NAME_MIN => [2, 200],
            FormRules::CR_NUMBER_MIN => [5, 50],
            FormRules::TAX_NUMBER_MIN => [5, 50],
            FormRules::TYPE_WORDS_MIN => [3, 100],
            FormRules::ANSWER_MIN => [2, 1000],
        ];

        foreach ($expected as $key => [$default, $max]) {
            $definition = app(SettingsRegistry::class)->definition($key);

            expect($definition?->scope)->toBe(SettingScope::Global)
                ->and($definition?->type)->toBe(SettingType::Integer)
                ->and($definition?->default)->toBe($default)
                ->and($definition?->rules)->toBe(['min:1', "max:{$max}"])
                ->and($definition?->permission)->toBe(PlatformPermissions::SETTINGS_UPDATE);
        }

        expect(fn () => formRulesMinimum(FormRules::NAME_MIN, 0))->toThrow(InvalidSettingValue::class)
            ->and(fn () => formRulesMinimum(FormRules::NAME_MIN, 201))->toThrow(InvalidSettingValue::class);
    });

    it('refuses a value shorter than its minimum on its own field, saving nothing, and takes one that reaches it', function (array $short, array $enough, string $field) {
        $customerId = formRulesDraft();

        $refused = formRulesRefusal(fn () => formRulesSave($short));
        $before = formRulesOpen($customerId);
        formRulesSave($enough);
        $after = formRulesOpen($customerId);

        expect($refused?->attribute)->toBe($field)
            ->and($refused?->reason)->toContain('at least')
            ->and([$before->name(), $before->type(), $before->crNumber(), $before->taxNumber()])->toBe([null, null, null, null])
            ->and([$after->name(), $after->type(), $after->crNumber(), $after->taxNumber()])->not->toBe([null, null, null, null]);
    })->with([
        'the name, 2' => [['name' => 'A'], ['name' => 'AB'], 'name'],
        'the CR number, 5' => [['cr_number' => '1234'], ['cr_number' => '12345'], 'cr_number'],
        'the tax number, 5' => [['tax_number' => '1234'], ['tax_number' => '12345'], 'tax_number'],
        '"Other"\'s words, 3' => [['company_type_id' => null, 'company_type_other' => 'ab'], ['company_type_id' => null, 'company_type_other' => 'abc'], 'company_type_other'],
    ]);

    it('counts characters, not bytes, and not the spaces at either end', function () {
        $customerId = formRulesDraft();

        expect(formRulesRefusal(fn () => formRulesSave(['name' => '  A  '])))->not->toBeNull()
            // One letter, two bytes: short, however many bytes it takes.
            ->and(formRulesRefusal(fn () => formRulesSave(['name' => 'ن'])))->not->toBeNull()
            ->and(formRulesRefusal(fn () => formRulesSave(['name' => 'نو'])))->toBeNull()
            ->and(formRulesOpen($customerId)->name()?->value)->toBe('نو');
    });

    it('holds a text answer to its minimum, and the note to none', function () {
        $text = strtolower((string) Str::ulid());
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($text, RequestKind::Text, 'Who signs for the company?', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);

        $refused = formRulesRefusal(fn () => app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'a')));
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'Me'));
        formRulesSave(['note' => 'a']);

        expect($refused?->attribute)->toBe('answer')
            ->and(formRulesOpen($customerId)->answers()[$text]->text?->value)->toBe('Me')
            ->and(formRulesOpen($customerId)->note()?->value)->toBe('a');
    });

    it('follows the setting when an admin changes it', function () {
        formRulesDraft();
        formRulesMinimum(FormRules::NAME_MIN, 4);
        formRulesMinimum(FormRules::CR_NUMBER_MIN, 1);

        expect(formRulesRefusal(fn () => formRulesSave(['name' => 'Abc'])))->not->toBeNull()
            ->and(formRulesRefusal(fn () => formRulesSave(['name' => 'Abcd'])))->toBeNull()
            ->and(formRulesRefusal(fn () => formRulesSave(['cr_number' => '1'])))->toBeNull();
    });

    it('holds every value again when it is sent: one saved before a minimum was raised is not sent, and takes no number', function () {
        $customerId = formRulesDraft();
        formRulesSave([
            'name' => 'Al Noor Trading',
            'company_type_id' => B2BFixtures::companyTypes()[0]->id(),
            'cr_number' => '1010123456',
            'tax_number' => '300123456700003',
            'address_id' => B2BFixtures::savedAddress($customerId),
        ]);

        foreach (formRulesDocumentTypeIds() as $typeId) {
            formRulesAttach($typeId, "paper-{$typeId}.pdf");
        }

        formRulesMinimum(FormRules::TAX_NUMBER_MIN, 20);
        $refused = formRulesRefusal(fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication));

        expect($refused?->attribute)->toBe('tax_number')
            ->and(formRulesOpen($customerId)->submittedAt())->toBeNull()
            ->and(DB::table('b2b.companies')->where('customer_id', $customerId)->exists())->toBeFalse()
            ->and(DB::table('b2b.application_reference_counters')->count())->toBe(0);

        formRulesMinimum(FormRules::TAX_NUMBER_MIN, 5);
        app(SubmitApplicationHandler::class)->handle(new SubmitApplication);

        expect(DB::table('b2b.companies')->where('customer_id', $customerId)->value('status'))->toBe('PENDING');
    });

    it('holds each answer again when it is sent', function () {
        $text = strtolower((string) Str::ulid());
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($text, RequestKind::Text, 'Who signs for the company?', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($text, text: 'Me'));

        formRulesMinimum(FormRules::ANSWER_MIN, 5);
        $refused = formRulesRefusal(fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication));

        expect($refused?->attribute)->toBe('answer')
            ->and(formRulesOpen($customerId)->submittedAt())->toBeNull();
    });

    it('hands the page the same numbers it checks, and the note its maximum alone', function () {
        formRulesMinimum(FormRules::NAME_MIN, 7);
        $rules = app(FormRules::class)->forPage();

        expect($rules['name'])->toBe(['min' => 7, 'max' => 200, 'oneLine' => true, 'characters' => null])
            ->and($rules['cr_number'])->toBe(['min' => 5, 'max' => 50, 'oneLine' => true, 'characters' => FormRules::NUMBER_CHARACTERS])
            ->and($rules['tax_number']['characters'])->toBe(FormRules::NUMBER_CHARACTERS)
            ->and($rules['company_type_other'])->toBe(['min' => 3, 'max' => 100, 'oneLine' => true, 'characters' => null])
            ->and($rules['answer'])->toBe(['min' => 2, 'max' => 1000, 'oneLine' => false, 'characters' => null])
            ->and($rules['note'])->toBe(['min' => 0, 'max' => 1000, 'oneLine' => false, 'characters' => null]);
    });
});

describe('the same file twice (amendment 16(c))', function () {
    it('refuses a paper named exactly as one under another document type, before anything is stored', function () {
        $customerId = formRulesDraft();
        [$first, $second] = formRulesDocumentTypeIds();
        formRulesAttach($first, 'scan.pdf');
        $files = DB::table('platform.media')->count();

        expect(fn () => formRulesAttach($second, 'scan.pdf'))->toThrow(DuplicateDocumentFile::class)
            ->and(DB::table('platform.media')->count())->toBe($files)
            ->and(array_keys(formRulesOpen($customerId)->documents()))->toBe([$first]);
    });

    it('takes the same name under the same type, which replaces it, and a name that is not exactly the same', function () {
        $customerId = formRulesDraft();
        [$first, $second] = formRulesDocumentTypeIds();
        formRulesAttach($first, 'scan.pdf');

        formRulesAttach($first, 'scan.pdf');
        formRulesAttach($second, 'Scan.pdf');

        expect(array_keys(formRulesOpen($customerId)->documents()))->toEqualCanonicalizing([$first, $second]);
    });

    it('does not compare the answers to what staff asked for', function () {
        $file = strtolower((string) Str::ulid());
        $customerId = B2BFixtures::verifiedCompanyAccount();
        B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($file, RequestKind::File, 'A bank letter', 1)]);
        Fx::actAsCustomer($customerId);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        $typeId = formRulesDocumentTypeIds()[0];
        formRulesAttach($typeId, 'letter.pdf');

        app(AnswerApplicationRequestHandler::class)->handle(new AnswerApplicationRequest($file, path: B2BFixtures::pdf(), originalFilename: 'letter.pdf'));

        expect(formRulesOpen($customerId)->answers()[$file]->mediaId)->not->toBeNull();
    });
});

describe('the address, picked from the saved addresses (amendment 16(f))', function () {
    it('keeps a copy of the one picked, and which it was, and clears it when none is sent', function () {
        $customerId = formRulesDraft();
        $addressId = B2BFixtures::savedAddress($customerId);

        formRulesSave(['address_id' => strtoupper($addressId)]);
        $picked = formRulesOpen($customerId)->address();
        formRulesSave(['address_id' => '']);

        expect($picked?->value)->toBe(B2BFixtures::addressText($addressId))
            ->and($picked?->addressId)->toBe($addressId)
            ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('address'))->toBeNull()
            ->and(DB::table('b2b.applications')->where('customer_id', $customerId)->value('address_id'))->toBeNull();
    });

    it('takes any store\'s saved address, not only the home store\'s', function () {
        $customerId = formRulesDraft();
        $addressId = B2BFixtures::savedAddress($customerId, 'eg');

        formRulesSave(['address_id' => $addressId]);

        expect(formRulesOpen($customerId)->address()?->addressId)->toBe($addressId);
    });

    it('refuses on the address one that is not the account\'s to pick, and saves nothing', function (Closure $picked) {
        $customerId = formRulesDraft();
        $addressId = $picked($customerId);

        $refused = formRulesRefusal(fn () => formRulesSave(['address_id' => $addressId, 'name' => 'Al Noor Trading']));

        expect($refused?->attribute)->toBe('address')
            ->and(formRulesOpen($customerId)->address())->toBeNull()
            ->and(formRulesOpen($customerId)->name())->toBeNull();
    })->with([
        'another account\'s' => [fn (string $customerId): string => B2BFixtures::savedAddress(B2BFixtures::companyAccount())],
        'one that does not exist' => [fn (string $customerId): string => strtolower((string) Str::ulid())],
        'not an id at all' => [fn (string $customerId): string => 'King Fahd Road'],
        'one its format no longer accepts' => [function (string $customerId): string {
            $addressId = B2BFixtures::savedAddress($customerId);
            DB::table('access.addresses')->where('id', $addressId)->update(['fields' => json_encode(['administrative_area' => 'Riyadh', 'city' => 'Riyadh'])]);

            return $addressId;
        }],
    ]);

    it('keeps the copy when the saved address is deleted, forgetting only where it came from', function () {
        $customerId = formRulesDraft();
        $addressId = B2BFixtures::savedAddress($customerId);
        formRulesSave(['address_id' => $addressId]);
        $kept = B2BFixtures::addressText($addressId);

        DB::table('access.addresses')->where('id', $addressId)->delete();

        expect(formRulesOpen($customerId)->address()?->value)->toBe($kept)
            ->and(formRulesOpen($customerId)->address()?->addressId)->toBeNull();
    });

    it('holds a copy as long as Access writes one, and no longer (§5.1)', function () {
        $customerId = formRulesDraft();
        $id = formRulesOpen($customerId)->id();

        $long = str_repeat('a', 6000);
        DB::table('b2b.applications')->where('id', $id)->update(['address' => $long]);

        expect(DB::table('b2b.applications')->where('id', $id)->value('address'))->toBe($long)
            ->and(fn () => DB::table('b2b.applications')->where('id', $id)->update(['address' => $long.'a']))->toThrow(QueryException::class, 'applications_address_length');
    });
});

describe('after the review of amendment 16 (amendment 17)', function () {
    it('holds each value again when it is sent, whichever was saved before its minimum was raised', function (string $setting, string $field, array $values) {
        $customerId = formRulesDraft();
        formRulesSave([
            'name' => 'Al Noor Trading',
            'company_type_id' => B2BFixtures::companyTypes()[0]->id(),
            'cr_number' => '1010123456',
            'tax_number' => '300123456700003',
            'address_id' => B2BFixtures::savedAddress($customerId),
            ...$values,
        ]);

        foreach (formRulesDocumentTypeIds() as $typeId) {
            formRulesAttach($typeId, "paper-{$typeId}.pdf");
        }

        formRulesMinimum($setting, 40);
        $refused = formRulesRefusal(fn () => app(SubmitApplicationHandler::class)->handle(new SubmitApplication));

        expect($refused?->attribute)->toBe($field)
            ->and(formRulesOpen($customerId)->submittedAt())->toBeNull();
    })->with([
        'the name' => [FormRules::NAME_MIN, 'name', []],
        'the CR number' => [FormRules::CR_NUMBER_MIN, 'cr_number', []],
        'the tax number' => [FormRules::TAX_NUMBER_MIN, 'tax_number', []],
        '"Other"\'s words' => [FormRules::TYPE_WORDS_MIN, 'company_type_other', ['company_type_id' => null, 'company_type_other' => 'Cooperative society']],
    ]);

    it('trims what the page trims: a value pasted with an invisible space at its end is taken, and kept without it (17(a))', function () {
        $customerId = formRulesDraft();

        formRulesSave(['name' => "Al Noor Trading\u{00A0}", 'cr_number' => "\u{FEFF}1010123456\u{3000}"]);

        expect(formRulesOpen($customerId)->name()?->value)->toBe('Al Noor Trading')
            ->and(formRulesOpen($customerId)->crNumber()?->value)->toBe('1010123456')
            // One letter and a no-break space is one letter: short, on the page and here alike.
            ->and(formRulesRefusal(fn () => formRulesSave(['name' => "A\u{00A0}"]))?->attribute)->toBe('name');
    });

    it('compares a paper\'s name as the media library keeps it (17(d))', function (string $again) {
        $customerId = formRulesDraft();
        [$first, $second] = formRulesDocumentTypeIds();
        formRulesAttach($first, 'scan.pdf');

        expect(fn () => formRulesAttach($second, $again))->toThrow(DuplicateDocumentFile::class)
            ->and(array_keys(formRulesOpen($customerId)->documents()))->toBe([$first]);
    })->with([
        'with a right-to-left mark' => ["scan\u{200F}.pdf"],
        'with spaces at the ends' => ['  scan.pdf '],
        'from a folder' => ['C:\Scans\scan.pdf'],
    ]);

    it('tells a suspended company, or an account with nothing open, so before weighing any value (17(h))', function () {
        $none = B2BFixtures::verifiedCompanyAccount();
        Fx::actAsCustomer($none);

        expect(fn () => formRulesSave(['name' => 'A', 'cr_number' => 'CR#1']))->toThrow(ApplicationNotFound::class);

        $suspended = B2BFixtures::verifiedCompanyAccount();
        [$company] = B2BFixtures::rejected($suspended);
        Fx::actAsCustomer($suspended);
        app(StartApplicationDraftHandler::class)->handle(new StartApplicationDraft);
        B2BFixtures::suspend($company);

        expect(fn () => formRulesSave(['name' => 'A', 'address_id' => 'not an address']))->toThrow(CompanySuspended::class);
    });

    it('refuses on the address a write naming a saved address deleted meanwhile, rather than failing (17(h))', function () {
        $customerId = formRulesDraft();
        $draft = formRulesOpen($customerId);
        $gone = strtolower((string) Str::ulid());
        $draft->describe(null, null, null, null, CompanyAddress::reconstitute('7 King Fahd Road', $gone), null);

        // In a transaction of its own, as every use case writes: a refused statement ends it.
        $refused = formRulesRefusal(fn () => DB::transaction(fn () => app(ApplicationRepository::class)->update($draft)));

        [$company] = B2BFixtures::approved(B2BFixtures::verifiedCompanyAccount());
        $company->moveTo(CompanyAddress::reconstitute('7 King Fahd Road', $gone));

        expect($refused?->attribute)->toBe('address')
            ->and(formRulesRefusal(fn () => DB::transaction(fn () => app(CompanyRepository::class)->update($company)))?->attribute)->toBe('address');
    });
});
