<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

/**
 * Content's screens over HTTP (content.md §4.4): signed in through the panel's door, a page's change sent as Inertia
 * sends it, the words a refused form went back with, and a page's queries counted on a warm second visit.
 */
final class ContentScreen
{
    public static function as(string $staffId): AdminBrowser
    {
        $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
        $browser->post('/admin/sign-in', [
            'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
            'password' => Fx::STAFF_PASSWORD,
        ])->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        return $browser;
    }

    /** @return array<string, string> */
    public static function fromPage(string $page): array
    {
        return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_REFERER' => url($page)];
    }

    /** @var array<string, string> */
    public const array JSON = ['HTTP_ACCEPT' => 'application/json'];

    /**
     * The words a form went back with, by box.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    public static function errors(TestResponse $response): array
    {
        $errors = AdminBrowser::flashed($response, 'errors');
        $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : (is_array($errors) ? ($errors['default']['messages'] ?? []) : []);

        return array_map(static fn (array $messages): string => (string) ($messages[0] ?? ''), $bag);
    }

    /** The queries a page costs, on a warm visit (the stores and the permissions already read once). */
    public static function queries(AdminBrowser $browser, string $address): int
    {
        $browser->get($address)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get($address)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * The keys of the menu's entries the person is offered under Content.
     *
     * @return list<string>
     */
    public static function menu(AdminBrowser $browser): array
    {
        /** @var list<array{key: string, entries: list<array{key: string}>}> $sections */
        $sections = $browser->get('/admin')->viewData('page')['props']['menu'];

        foreach ($sections as $section) {
            if ($section['key'] === 'content') {
                return array_column($section['entries'], 'key');
            }
        }

        return [];
    }
}
