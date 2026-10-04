<?php

declare(strict_types=1);

use Modules\Catalog\Application\Search\SearchTerms;

/*
| What a shopper typed, as search reads it (catalog.md §1.11): normalised as the listing is, cut into
| words of letters and digits only — so nothing typed reaches the query's own syntax — and widened by
| the shared word pairs, a run of words before a single one.
*/

it('reads what was typed as the listing was written: normalised, words of letters and digits only', function () {
    $terms = SearchTerms::of("  Soft-Close   مِفْصَلة & ٣٥mm ' | !  ");

    expect($terms->text)->toBe("soft-close مفصله & 35mm ' | !")
        ->and($terms->words)->toBe(['soft', 'close', 'مفصله', '35mm'])
        ->and($terms->inNames())->toBe('soft:*A & close:*A & مفصله:*A & 35mm:*A')
        ->and($terms->inWords())->toBe('(soft:*AB) & (close:*AB) & (مفصله:*AB) & (35mm:*AB)')
        ->and($terms->anywhere())->toBe('(soft:*ABC) & (close:*ABC) & (مفصله:*ABC) & (35mm:*ABC)');
});

it('searches nothing for words with no letter or digit', function (string $typed) {
    expect(SearchTerms::of($typed)->isEmpty())->toBeTrue();
})->with(['nothing' => [''], 'spaces' => ['   '], 'marks only' => ['?! & | :*']]);

it('keeps at most ten words and two hundred characters, as the log does', function () {
    $terms = SearchTerms::of(implode(' ', array_map(static fn (int $n): string => "w{$n}", range(1, 15))).' '.str_repeat('x', 300));

    expect($terms->words)->toHaveCount(SearchTerms::WORDS_MAX)
        ->and($terms->words[9])->toBe('w10')
        ->and(mb_strlen($terms->text))->toBe(SearchTerms::TEXT_MAX);
});

it('widens a word by each pair that names it, either way round', function () {
    $terms = SearchTerms::of('hinge plate')->withPairs([['hinge', 'مفصله'], ['plate', 'لوح'], ['base', 'plate'], ['door', 'باب']]);

    expect($terms->inWords())->toBe('(hinge:*AB | مفصله:*AB) & (plate:*AB | لوح:*AB | base:*AB)')
        ->and($terms->anywhere())->toBe('(hinge:*ABC | مفصله:*ABC) & (plate:*ABC | لوح:*ABC | base:*ABC)')
        // The names question never takes a pair: a name holding the words typed ranks above it.
        ->and($terms->inNames())->toBe('hinge:*A & plate:*A');
});

it('widens a run of words by a pair of several words before any one of them', function () {
    $terms = SearchTerms::of('soft close hinge')->withPairs([['close', 'غلق'], ['soft close', 'ناعم الاغلاق'], ['hinge', 'مفصله']]);

    expect($terms->inWords())->toBe('(soft:*AB <-> close:*AB | ناعم:*AB <-> الاغلاق:*AB) & (hinge:*AB | مفصله:*AB)');
});

it('leaves a pair that names only part of a word, or no word typed, out', function () {
    $terms = SearchTerms::of('hinge')->withPairs([['hinges', 'مفصلات'], ['soft close', 'ناعم']]);

    expect($terms->inWords())->toBe('(hinge:*AB)');
});
