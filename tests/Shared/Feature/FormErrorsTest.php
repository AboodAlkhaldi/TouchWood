<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Platform\Domain\Exception\InvalidStoreAttribute;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\post;

/*
| What a refused form says, in the person's language (frontend.md 2.1).
|
| A refusal carries the field's **key** - "current_password", "date_of_birth" - because that is
| what the domain calls it and what the form posted. These prove the key never reaches the person
| written the way it is written in the code.
*/

it('names the field in words, not in the key the domain uses', function (string $locale, string $expected) {
    app()->setLocale($locale);

    expect(FormErrors::message(new InvalidAccessAttribute('current_password', 'not the current password')))
        ->toBe($expected);
})->with([
    ['en', "Couldn't save: the current password isn't valid. Check it and try again."],
    ['ar', 'تعذّر الحفظ: قيمة كلمة المرور الحالية غير صالحة. تحقّق منها وحاول مجددًا.'],
]);

it('takes the field name from whichever module refused, not always from Access', function () {
    app()->setLocale('en');

    // Platform names its own fields, in its own file, because it is the one that refused.
    expect(FormErrors::message(new InvalidStoreAttribute('sign', 'not one character')))
        ->toBe("Couldn't save the store: the symbol isn't valid. Check it and try again.");
});

it('falls back to the key without its underscores when nobody has written the field down', function () {
    app()->setLocale('en');

    // Poor, and still better than "no_such_field_here" - a missing line in a language file must
    // never be the reason somebody cannot read why they were refused.
    expect(FormErrors::message(new InvalidAccessAttribute('no_such_field_here', 'invented for this test')))
        ->toBe("Couldn't save: the no such field here isn't valid. Check it and try again.");
});

/**
 * The keys of the errors a response flashed, however the session kept the bag.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function formErrorsKeys(TestResponse $response): array
{
    $errors = $response->baseResponse instanceof RedirectResponse ? $response->baseResponse->getSession()?->get('errors') : null;

    if ($errors instanceof ViewErrorBag) {
        return array_values(array_map('strval', $errors->getBag('default')->keys()));
    }

    return is_array($errors) ? array_map('strval', array_keys($errors['default']['messages'] ?? [])) : [];
}

/*
| A batch refusal names its rows (pricing.md §4.3.1, Q2): the sizes in its `variants` context reach
| the page as `errors["rows.{id}"]`, each with the refusal's message, beside `errors.form`.
*/

it('names each size a batch refusal concerns, with the refusal\'s message', function () {
    app()->setLocale('en');
    $error = new NoRetailPrice(['01K7AAAAAAAAAAAAAAAAAAAAAA', ' 01K7BBBBBBBBBBBBBBBBBBBBBB ']);

    expect(FormErrors::rows($error))->toBe([
        '01K7AAAAAAAAAAAAAAAAAAAAAA' => FormErrors::message($error),
        '01K7BBBBBBBBBBBBBBBBBBBBBB' => FormErrors::message($error),
    ])->and(FormErrors::message($error))->toBe('This size has no retail price in the store. Give it a price before putting it on any offer or giving it wholesale prices.');
});

/*
| A refusal naming the rule it broke (pricing.md §7, owner Q12) says that rule's words - the module's
| `rules.{rule}` - on a page and in a problem response alike; one with no words for its rule says the
| error's detail.
*/

it('says the words of the rule a refusal names, in either language', function (string $locale, string $expected) {
    app()->setLocale($locale);

    expect(FormErrors::message(new BandsInvalid(BandsInvalid::FIRST_BAND, 20, 500, variants: ['01K7AAAAAAAAAAAAAAAAAAAAAA'])))->toBe($expected);
})->with([
    ['en', 'The first band must start at 20, the wholesale minimum.'],
    ['ar', 'يجب أن تبدأ الشريحة الأولى من 20، وهو الحدّ الأدنى للجملة.'],
]);

it('says the error\'s detail for a rule it has no words for, and for a rule that is not one', function () {
    app()->setLocale('en');
    $detail = 'The first band starts at the wholesale minimum and costs less than the retail price; each next band starts at a higher quantity and costs less.';

    expect(FormErrors::message(new BandsInvalid('no_such_rule')))->toBe($detail)
        ->and(FormErrors::message(new BandsInvalid('Not-A-Rule')))->toBe($detail)
        ->and(FormErrors::message(new BandsInvalid(BandsInvalid::BELOW_RETAIL, retail: '120.00')))->toBe('The first band must cost less than 120.00, the retail price.');
});

it('answers a client asking for JSON with the rule\'s words too', function () {
    Route::post('/_test/bands', static fn () => throw new BandsInvalid(BandsInvalid::TOO_MANY));
    app()->setLocale('en');

    post('/_test/bands', [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('detail', 'A size can have at most 10 bands.');
});

it('names no row for a refusal about no size, and never one that is not an id', function () {
    expect(FormErrors::rows(new PriceNotPositive))->toBe([])
        ->and(FormErrors::rows(new NoRetailPrice([])))->toBe([])
        ->and(FormErrors::rows(new InvalidAccessAttribute('current_password', 'not the current password')))->toBe([])
        // A key is built from it: anything but letters and digits is left out.
        ->and(array_keys(FormErrors::rows(new NoRetailPrice(['a.b', 'x y', '01K7CCCCCCCCCCCCCCCCCCCCCC']))))->toBe(['01K7CCCCCCCCCCCCCCCCCCCCCC']);
});

it('sends the rows back with the form\'s message, each under its own key', function () {
    app()->setLocale('en');
    Route::post('/_test/form-errors-rows', fn (Request $request) => FormErrors::back($request, new NoRetailPrice(['01K7AAAAAAAAAAAAAAAAAAAAAA', '01K7BBBBBBBBBBBBBBBBBBBBBB'])))->middleware('web');

    $response = post('/_test/form-errors-rows')->assertRedirect()->assertSessionHasErrors(['form', 'rows.01K7AAAAAAAAAAAAAAAAAAAAAA', 'rows.01K7BBBBBBBBBBBBBBBBBBBBBB']);

    expect(formErrorsKeys($response))->toBe(['form', 'rows.01K7AAAAAAAAAAAAAAAAAAAAAA', 'rows.01K7BBBBBBBBBBBBBBBBBBBBBB']);
});

it('sends only the form\'s message for a refusal about no size, as before', function () {
    Route::post('/_test/form-errors-plain', fn (Request $request) => FormErrors::back($request, new PriceNotPositive))->middleware('web');

    expect(formErrorsKeys(post('/_test/form-errors-plain')->assertRedirect()))->toBe(['form']);
});
