<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Modules\Sync\Domain\Exception\ConnectionFailed;
use Modules\Sync\Domain\ValueObject\ProviderReason;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * @param  array<string, string>  $context
 */
function fakeDomainError(string $type, ErrorCategory $category, string $message = 'Developer message.', array $context = []): DomainError
{
    return new class($type, $category, $message, $context) extends DomainError
    {
        /**
         * @param  array<string, string>  $values
         */
        public function __construct(
            private readonly string $errorType,
            private readonly ErrorCategory $errorCategory,
            string $message,
            private readonly array $values,
        ) {
            parent::__construct($message);
        }

        public function type(): string
        {
            return $this->errorType;
        }

        public function category(): ErrorCategory
        {
            return $this->errorCategory;
        }

        public function context(): array
        {
            return $this->values;
        }
    };
}

beforeEach(function () {
    app('translator')->addNamespace('testing', __DIR__.'/fixtures/lang');

    Route::get('/_test/category/{category}', function (string $category) {
        throw fakeDomainError('testing.not_translated', ErrorCategory::from($category));
    });
    Route::get('/_test/connection-failed', function () {
        throw new ConnectionFailed(new ProviderReason(ProviderReason::FAILED, ['status' => 503]), 'Odoo failed (503).');
    });
    Route::get('/_test/thing-taken', function () {
        throw fakeDomainError('testing.thing_taken', ErrorCategory::Conflict, 'Code sa is taken.', ['code' => 'sa']);
    });
    Route::get('/_test/unauthorized', function () {
        throw new Unauthorized('platform.store.update');
    });
    Route::post('/_test/validated', function (Request $request) {
        $request->validate(['name' => ['required']]);

        return 'ok';
    });
    Route::get('/_test/bug', function () {
        throw new RuntimeException('SQLSTATE[42P01]: secret table name leaked');
    });
});

it('gives every error category its HTTP status', function (string $category, int $status) {
    getJson("/_test/category/{$category}")
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'testing.not_translated')
        ->assertJsonPath('status', $status);
})->with([
    ['NOT_FOUND', 404],
    ['FORBIDDEN', 403],
    ['CONFLICT', 409],
    ['INVALID', 422],
    ['UNSUPPORTED', 415],
    ['TOO_LARGE', 413],
]);

it('renders the same problem shape for every error', function () {
    getJson('/_test/thing-taken')
        ->assertExactJsonStructure(['type', 'title', 'status', 'detail', 'correlation_id']);
});

it('translates the module error in English', function () {
    app()->setLocale('en');

    getJson('/_test/thing-taken')
        ->assertJsonPath('title', 'Thing already taken')
        ->assertJsonPath('detail', 'Another thing already uses the code "sa".');
});

it('translates the module error in Arabic', function () {
    app()->setLocale('ar');

    getJson('/_test/thing-taken')
        ->assertJsonPath('title', 'الرمز مستخدم')
        ->assertJsonPath('detail', 'الرمز "sa" مستخدم بالفعل.');
});

it('translates errors owned by the shared kernel', function () {
    app()->setLocale('ar');

    getJson('/_test/unauthorized')
        ->assertStatus(403)
        ->assertJsonPath('type', 'shared.unauthorized')
        ->assertJsonPath('title', 'غير مسموح');
});

it('falls back to the category title and the status\'s own JSON sentence when an error has no translation, never the page\'s or the developer message (owner, 2026-10-20; stage 5 step 10)', function (string $locale, string $category, ?string $title, string $detail) {
    app()->setLocale($locale);

    $response = getJson("/_test/category/{$category}")->assertJsonPath('detail', $detail);

    if ($title !== null) {
        $response->assertJsonPath('title', $title);
    }

    expect($response->getContent())->not->toContain('Developer message')
        ->and($response->getContent())->not->toContain(json_encode(trans('errors.page.'.$response->status()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
})->with([
    'a conflict' => ['en', 'CONFLICT', 'Conflict', 'Couldn\'t do that: something changed meanwhile. Reload and try again.'],
    'not found' => ['en', 'NOT_FOUND', 'Not Found', 'Couldn\'t do that: what it names isn\'t there.'],
    'forbidden' => ['en', 'FORBIDDEN', 'Not Allowed', 'Couldn\'t do that: you don\'t have access to it.'],
    'an invalid request' => ['en', 'INVALID', 'Invalid Request', 'Couldn\'t do that: something sent isn\'t valid.'],
    'unsupported' => ['en', 'UNSUPPORTED', 'Unsupported', 'Couldn\'t do that: what was sent isn\'t a kind it takes.'],
    'too large' => ['en', 'TOO_LARGE', 'Too Large', 'Couldn\'t do that: what was sent is too large.'],
    'a conflict, in Arabic' => ['ar', 'CONFLICT', null, 'تعذّر تنفيذ ذلك: تغيّر شيء في الأثناء. أعد التحميل وحاول مجددًا.'],
    'not found, in Arabic' => ['ar', 'NOT_FOUND', null, 'تعذّر تنفيذ ذلك: ما يسمّيه غير موجود.'],
    'forbidden, in Arabic' => ['ar', 'FORBIDDEN', null, 'تعذّر تنفيذ ذلك: ليست لديك صلاحية له.'],
    'an invalid request, in Arabic' => ['ar', 'INVALID', 'طلب غير صالح', 'تعذّر تنفيذ ذلك: في ما أُرسل ما ليس صالحًا.'],
    'unsupported, in Arabic' => ['ar', 'UNSUPPORTED', null, 'تعذّر تنفيذ ذلك: ما أُرسل من نوع لا يقبله.'],
    'too large, in Arabic' => ['ar', 'TOO_LARGE', null, 'تعذّر تنفيذ ذلك: ما أُرسل كبير جدًا.'],
]);

it('says a provider\'s reason kept as a code through its rule in the page\'s language, never the stored code or the developers\' message (stage 5 step 10)', function (string $locale, string $detail) {
    app()->setLocale($locale);

    $response = getJson('/_test/connection-failed')
        ->assertStatus(422)
        ->assertJsonPath('type', 'sync.connection_failed')
        ->assertJsonPath('detail', $detail);

    expect($response->getContent())->not->toContain('ODOO_FAILED')
        // The developers' message ("The provider could not be read: …"), never; the title is its own words.
        ->and($response->getContent())->not->toContain('could not be read:');
})->with([
    'English' => ['en', 'Failed to read the provider: Odoo failed (503).'],
    'Arabic' => ['ar', 'تعذّرت القراءة من المزوّد: تعطّل Odoo (503).'],
]);

it('says no developer message in a JSON problem for any category', function (string $category) {
    expect(getJson("/_test/category/{$category}")->getContent())->not->toContain('Developer message');
})->with(['NOT_FOUND', 'FORBIDDEN', 'CONFLICT', 'INVALID', 'UNSUPPORTED', 'TOO_LARGE']);

it('does not report expected business errors as bugs', function () {
    Exceptions::fake();

    getJson('/_test/thing-taken');

    Exceptions::assertNotReported(DomainError::class);
});

it('gives a page request the error page for the matching status, not a 500', function () {
    get('/_test/thing-taken')->assertStatus(409);
});

it('shows a page request the translated title, never the message written for developers', function () {
    app()->setLocale('en');

    get('/_test/unauthorized')
        ->assertForbidden()
        ->assertSee('Not Allowed')
        ->assertDontSee('platform.store.update');
});

it('renders validation failures in the same shape with the field errors', function () {
    app()->setLocale('en');

    postJson('/_test/validated', [])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'validation_failed')
        ->assertJsonPath('title', 'Invalid Data')
        ->assertJsonStructure(['errors' => ['name']]);
});

it('renders an unknown route as a problem in the visitor language', function () {
    app()->setLocale('ar');

    getJson('/_test/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('type', 'http.404')
        ->assertJsonPath('title', 'الصفحة غير موجودة');
});

it('answers a bug with a generic 500 that reveals nothing, and reports it', function () {
    Exceptions::fake();

    $response = getJson('/_test/bug')
        ->assertStatus(500)
        ->assertJsonPath('type', 'http.500');

    expect($response->getContent())->not->toContain('SQLSTATE');
    expect($response->getContent())->not->toContain('secret');
    Exceptions::assertReported(RuntimeException::class);
});
