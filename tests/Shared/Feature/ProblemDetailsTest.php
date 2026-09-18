<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
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

it('falls back to the category title and the developer message when an error has no translation', function () {
    app()->setLocale('en');

    getJson('/_test/category/CONFLICT')
        ->assertJsonPath('title', 'Conflict')
        ->assertJsonPath('detail', 'Developer message.');
});

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
        ->assertSee('Not allowed')
        ->assertDontSee('platform.store.update');
});

it('renders validation failures in the same shape with the field errors', function () {
    app()->setLocale('en');

    postJson('/_test/validated', [])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'validation_failed')
        ->assertJsonPath('title', 'Invalid data')
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
