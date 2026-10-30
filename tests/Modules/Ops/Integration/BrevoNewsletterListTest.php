<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Infrastructure\External\BrevoNewsletterList;
use Modules\Ops\Infrastructure\External\LogNewsletterList;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The newsletter's adapter (ops.md §1.9, §2.2): `log` by default; Brevo by configuration, its requests
| exactly as Brevo's reference shows them, read 2026-10-29 - create a list
| (https://developers.brevo.com/reference/create-list.md), create a contact
| (https://developers.brevo.com/reference/createcontact), remove contacts from a list
| (https://developers.brevo.com/reference/removecontactfromlist), delete a contact
| (https://developers.brevo.com/reference/deletecontact). No real network: Http::fake answers, and
| refuses anything not faked.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Http::preventStrayRequests();
    config(['ops.newsletter.driver' => 'brevo', 'services.brevo.key' => 'test-key', 'services.brevo.newsletter_folder' => '7']);
});

function brevoListStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

it('is the log driver by default, and Brevo by configuration', function () {
    config(['ops.newsletter.driver' => 'log']);
    expect(app(NewsletterList::class))->toBeInstanceOf(LogNewsletterList::class);

    config(['ops.newsletter.driver' => 'brevo']);
    expect(app(NewsletterList::class))->toBeInstanceOf(BrevoNewsletterList::class);

    config(['ops.newsletter.driver' => 'mailchimp']);
    expect(fn () => app(NewsletterList::class))->toThrow(InvalidArgumentException::class);
});

it('makes the store\'s list of that language once, in the folder, then puts contacts on it', function () {
    Http::fake([
        'api.brevo.com/v3/contacts/lists' => Http::response(['id' => 41], 201),
        'api.brevo.com/v3/contacts' => Http::response(['id' => 900], 201),
    ]);

    app(NewsletterList::class)->add('sara@example.test', brevoListStore(), 'ar');
    app(NewsletterList::class)->add('omar@example.test', brevoListStore(), 'ar');

    $requests = Http::recorded()->map(fn (array $pair): array => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data(), $pair[0]->header('api-key')])->all();
    expect($requests)->toBe([
        ['POST', 'https://api.brevo.com/v3/contacts/lists', ['name' => 'TouchWood sa ar', 'folderId' => 7], ['test-key']],
        ['POST', 'https://api.brevo.com/v3/contacts', ['email' => 'sara@example.test', 'listIds' => [41], 'updateEnabled' => true], ['test-key']],
        ['POST', 'https://api.brevo.com/v3/contacts', ['email' => 'omar@example.test', 'listIds' => [41], 'updateEnabled' => true], ['test-key']],
    ])->and(DB::table('ops.newsletter_lists')->first())->toMatchArray(['store_id' => Fx::storeId('sa'), 'locale' => 'ar', 'brevo_list_id' => 41]);
});

it('takes an email off its list, nothing when no list was ever made, and deletes a contact - one already gone is fine', function () {
    DB::table('ops.newsletter_lists')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'brevo_list_id' => 41, 'created_at' => now()]);
    Http::fake([
        'api.brevo.com/v3/contacts/lists/41/contacts/remove' => Http::response(['contacts' => ['success' => ['sara@example.test']]], 201),
        'api.brevo.com/v3/contacts/*' => Http::response(['code' => 'document_not_found', 'message' => 'Contact does not exist'], 404),
    ]);

    app(NewsletterList::class)->remove('sara@example.test', brevoListStore(), 'en');
    app(NewsletterList::class)->remove('sara@example.test', brevoListStore('ae'), 'en');
    app(NewsletterList::class)->forget('sara+news@example.test');

    $requests = Http::recorded()->map(fn (array $pair): array => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data()])->all();
    expect($requests)->toBe([
        ['POST', 'https://api.brevo.com/v3/contacts/lists/41/contacts/remove', ['emails' => ['sara@example.test']]],
        ['DELETE', 'https://api.brevo.com/v3/contacts/sara%2Bnews%40example.test', []],
    ]);
});

it('lets a contact receive email again - Brevo\'s update contact, emailBlacklisted false (https://developers.brevo.com/reference/updatecontact)', function () {
    Http::fake(['api.brevo.com/v3/contacts/*' => Http::sequence()->push(null, 204)->push(['code' => 'document_not_found', 'message' => 'Contact does not exist'], 404)->push(['code' => 'invalid_parameter', 'message' => 'bad'], 400)]);

    app(NewsletterList::class)->allow('sara+news@example.test');
    app(NewsletterList::class)->allow('gone@example.test');

    $requests = Http::recorded()->map(fn (array $pair): array => [$pair[0]->method(), $pair[0]->url(), $pair[0]->data()])->all();
    expect($requests)->toBe([
        ['PUT', 'https://api.brevo.com/v3/contacts/sara%2Bnews%40example.test', ['emailBlacklisted' => false]],
        ['PUT', 'https://api.brevo.com/v3/contacts/gone%40example.test', ['emailBlacklisted' => false]],
    ])->and(fn () => app(NewsletterList::class)->allow('sara@example.test'))->toThrow(ProviderFailed::class, 'HTTP 400');
});

it('fails for the queue to try again: Brevo refuses, cannot be reached, no key, no folder - never naming the email', function () {
    Http::fake(['api.brevo.com/*' => Http::response(['code' => 'invalid_parameter', 'message' => 'bad'], 400)]);
    DB::table('ops.newsletter_lists')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'brevo_list_id' => 41, 'created_at' => now()]);

    expect(fn () => app(NewsletterList::class)->add('sara@example.test', brevoListStore(), 'en'))->toThrow(ProviderFailed::class, 'Brevo refused the contact: HTTP 400 (invalid_parameter).');

    try {
        app(NewsletterList::class)->add('sara@example.test', brevoListStore(), 'en');
    } catch (ProviderFailed $failed) {
        expect($failed->getMessage())->not->toContain('sara');
    }

    Http::fake(fn (Request $request) => throw new ConnectionException('down'));
    expect(fn () => app(NewsletterList::class)->forget('sara@example.test'))->toThrow(ProviderFailed::class, 'could not be reached');

    config(['services.brevo.key' => '']);
    expect(fn () => app(NewsletterList::class)->forget('sara@example.test'))->toThrow(ProviderFailed::class, 'no API key');

    config(['services.brevo.key' => 'test-key', 'services.brevo.newsletter_folder' => '']);
    expect(fn () => app(NewsletterList::class)->add('sara@example.test', brevoListStore('ae'), 'en'))->toThrow(ProviderFailed::class, 'no folder');
});
