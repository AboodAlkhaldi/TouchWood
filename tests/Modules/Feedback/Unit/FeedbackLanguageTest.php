<?php

declare(strict_types=1);

use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Model\FeedbackText;

/*
| A text's language, from the text (feedback.md §4.2 B3; owner, 2026-10-24): its first letter decides — an
| Arabic letter, Arabic; any other letter, English — digits, spaces, signs and emoji skipped; no letter at
| all, English. Staff set Arabic or English only.
*/

it('takes the language of the first letter', function (string $text, string $language) {
    expect(FeedbackText::languageOf($text))->toBe($language);
})->with([
    'English' => ['Quiet hinges', 'en'],
    'Arabic' => ['مفصلات هادئة', 'ar'],
    'Arabic after digits and a space' => ['5 نجوم', 'ar'],
    'Arabic after emoji and signs' => ['😀!! «ممتاز»', 'ar'],
    'English after an Arabic sign' => ['؟ Is it quiet', 'en'],
    'Latin letters, Arabic words' => ['Mumtaz jiddan', 'en'],
    'a letter with an accent' => ['Élégant', 'en'],
    'Arabic first, then English' => ['جيد good', 'ar'],
    'no letter at all' => ['12345 !!!', 'en'],
    'empty' => ['', 'en'],
]);

it('takes Arabic or English from staff, nothing else', function (string $locale, bool $taken) {
    $call = fn (): string => FeedbackText::locale($locale);

    $taken ? expect($call())->toBe($locale) : expect($call)->toThrow(InvalidFeedbackAttribute::class);
})->with([
    ['ar', true],
    ['en', true],
    ['fr', false],
    ['AR', false],
    ['', false],
]);
