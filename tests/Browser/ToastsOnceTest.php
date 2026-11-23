<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| Each answer from the server is said once (resources/js/components/Toasts.tsx; the owner's report of 2026-11-24:
| "sometimes comes twice for the same action"). Every layout draws its own Toasts, so an action that lands on another
| page builds a new one: it spoke once as it was built and again when the visit finished. A watcher counts every toast
| drawn - sonner takes them away after a few seconds, so counting what is left would miss one.
|
| Deliberately no RefreshDatabase, as the other browser tests: every address carries the run's own number (lesson 84).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** Starts counting the toasts drawn from now on, by their words; survives Inertia's visits (one document). */
function toastsWatch(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__toasts = [];
            new MutationObserver((changes) => changes.forEach((change) => change.addedNodes.forEach((node) => {
                // The first toast arrives inside its new list, so the list is what is added: look inside it too.
                if (node instanceof HTMLElement) {
                    [node, ...node.querySelectorAll('*')].filter((element) => element.matches('[data-sonner-toast]'))
                        .forEach((toast) => window.__toasts.push(toast.textContent ?? ''));
                }
            }))).observe(document.body, { childList: true, subtree: true });
        })()
        JS);
}

/** How many toasts said these words, once things have settled. */
function toastsSaying(mixed $page, string $words): int
{
    expect(browserSoon($page, sprintf('window.__toasts.some((text) => text.includes(%s))', json_encode($words))))->toBeTrue();
    // A second toast, when there is one, follows within a frame or two: wait well past that before counting.
    usleep(1_500_000);

    return (int) $page->script(sprintf('window.__toasts.filter((text) => text.includes(%s)).length', json_encode($words)));
}

it('says an action that lands on another page once', function () {
    $email = 'toast-'.Cb::run().'@example.test';
    Fx::customer($email);

    $page = visit('/sa/en');
    expect(browserSoon($page, "document.readyState === 'complete'", 90))->toBeTrue();
    $page->navigate('/sa/en/sign-in', BROWSER_PAGE_LOAD);
    $page->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell\"]') !== null"))->toBeTrue();
    $page->navigate('/sa/en/account', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"shopper-menu\"]') !== null"))->toBeTrue();

    toastsWatch($page);
    // From the account page to the shop's home: another page, so another layout and another Toasts.
    $page->click('[data-test="shopper-menu"]')->click('[data-test="sign-out"]');

    expect(toastsSaying($page, 'Signed out'))->toBe(1);
    $page->assertNoJavaScriptErrors();
});

it('says an action that stays on its page once', function () {
    $page = Cb::signIn([ContentPermissions::SEO_MANAGE]);
    $page->navigate('/admin/content/seo?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"seo-form\"]') !== null"))->toBeTrue();

    $run = Cb::run();
    $page->clear('#site_name_ar')->type('#site_name_ar', "تاتش وود {$run}")
        ->clear('#site_name_en')->type('#site_name_en', "TouchWood {$run}")
        ->clear('#title_pattern_ar')->type('#title_pattern_ar', ':title | :site')
        ->clear('#title_pattern_en')->type('#title_pattern_en', ':title - :site')
        ->clear('#description_ar')->type('#description_ar', 'أدوات وخشب')
        ->clear('#description_en')->type('#description_en', 'Tools and wood');

    toastsWatch($page);
    $page->click('[data-test="save-seo"]');

    expect(toastsSaying($page, 'SEO saved'))->toBe(1);
    $page->assertNoJavaScriptErrors();
});

it('says a wrong password every time it is given, twice as twice', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');
    $page = visit('/admin/sign-in');
    expect(browserSoon($page, "document.querySelector('#password') !== null", 90))->toBeTrue();
    toastsWatch($page);

    $page->type('#email', $email)->type('#password', 'not-the-password-1')->click('button[type="submit"]');
    expect(browserSoon($page, 'window.__toasts.length === 1'))->toBeTrue();
    $words = (string) $page->script('window.__toasts[0]');
    $page->clear('#password')->type('#password', 'not-the-password-2')->click('button[type="submit"]');

    expect(browserSoon($page, 'window.__toasts.length === 2'))->toBeTrue()
        ->and($page->script('window.__toasts[1]'))->toBe($words);
    usleep(1_500_000);
    expect($page->script('window.__toasts.length'))->toBe(2);
});
