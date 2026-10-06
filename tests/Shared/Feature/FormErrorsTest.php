<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Platform\Domain\Exception\InvalidStoreAttribute;

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
