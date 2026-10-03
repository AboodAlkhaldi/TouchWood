<?php

declare(strict_types=1);

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Model\Label;
use Modules\Catalog\Domain\Model\WordPair;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Catalog\Domain\ValueObject\CatalogText;
use Modules\Catalog\Domain\ValueObject\LabelTone;
use Modules\Catalog\Domain\ValueObject\LocalizedName;
use Modules\Catalog\Domain\ValueObject\Slug;
use Modules\Catalog\Domain\ValueObject\Slugs;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Domain\ValueObject\WarrantyPeriod;

/*
| The values every Catalog list is made of (catalog.md §1.1–§1.11, amendment 1): text on one line,
| names in both languages, slugs, Arabic as search compares it, structured text, label words.
*/

describe('text on one line', function () {
    it('trims what a page trims, a no-break space and U+FEFF included', function () {
        expect(CatalogText::oneLine('name_en', " \u{00A0}Hinge\u{FEFF}\n", 100))->toBe('Hinge');
    });

    it('refuses empty, control characters inside, bad UTF-8 and too long', function (string $value, string $reason) {
        expect(fn () => CatalogText::oneLine('name_en', $value, 5))->toThrow(InvalidCatalogAttribute::class, $reason);
    })->with([
        'empty' => ['   ', 'required'],
        'a newline inside' => ["a\nb", 'on one line'],
        'a NUL' => ["a\0b", 'on one line'],
        'not UTF-8' => ["\xC3\x28", 'text'],
        'six characters' => ['abcdef', 'at most 5'],
    ]);

    it('takes five Arabic characters as five, not as their bytes', function () {
        expect(CatalogText::oneLine('name_ar', 'مفصلة', 5))->toBe('مفصلة');
    });
});

describe('a name in both languages', function () {
    it('needs both, and names the one missing', function () {
        expect(fn () => LocalizedName::of('مفصلة', '  ', 100))->toThrow(InvalidCatalogAttribute::class, 'name_en');
    });
});

describe('slugs', function () {
    it('makes a Latin slug from an English name, accents dropped', function () {
        expect(Slug::fromName('en', 'Häfele  Soft-Close Hinge, 110°')->value)->toBe('hafele-soft-close-hinge-110');
    });

    it('makes an Arabic slug from an Arabic name, tashkeel and tatweel dropped, Latin kept', function () {
        expect(Slug::fromName('ar', 'مَفْصَلة  هـادئة Blum ١١٠')->value)->toBe('مفصلة-هادئة-blum-110');
    });

    it('refuses a name with nothing to make a slug from', function () {
        expect(fn () => Slug::fromName('en', '— ° —'))->toThrow(InvalidCatalogAttribute::class, 'slug_en');
    });

    it('takes a typed slug as it is, held to its language\'s letters', function (string $locale, string $slug, bool $ok) {
        $make = fn () => Slug::of($locale, $slug);

        $ok ? expect($make()->value)->toBe($slug) : expect($make)->toThrow(InvalidCatalogAttribute::class);
    })->with([
        'en, plain' => ['en', 'soft-close-hinge', true],
        'en, upper case' => ['en', 'Soft-Close', false],
        'en, Arabic letters' => ['en', 'مفصلة', false],
        'en, a double hyphen' => ['en', 'soft--close', false],
        'en, a hyphen at the end' => ['en', 'soft-', false],
        'ar, Arabic and Latin' => ['ar', 'مفصلة-blum', true],
        'ar, a space' => ['ar', 'مفصلة هادئة', false],
        'ar, a slash' => ['ar', 'مفصلة/هادئة', false],
    ]);

    it('makes an empty slug from its name and keeps a typed one', function () {
        $slugs = Slugs::for(LocalizedName::of('مفصلة هادئة', 'Soft hinge', 100), en: 'quiet-hinge');

        expect($slugs->ar->value)->toBe('مفصلة-هادئة')->and($slugs->en->value)->toBe('quiet-hinge');
    });
});

describe('Arabic as search compares it', function () {
    it('drops the marks, makes the alefs one, reads ى as ي and ة as ه, and digits as Latin', function () {
        expect(ArabicText::normalize('أَحْمَد  إِلى  مُدرّسـة ٢٠٢٦'))->toBe('احمد الي مدرسه 2026');
    });

    it('lowers Latin letters', function () {
        expect(ArabicText::normalize('Soft-Close HINGE'))->toBe('soft-close hinge');
    });
});

describe('structured text', function () {
    /** @return array<string, mixed> */
    function catalogValuesDocument(): array
    {
        return ['blocks' => [
            ['type' => 'heading', 'runs' => [['text' => 'Fitting']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Fits '], ['text' => 'every', 'bold' => true], ['text' => ' cabinet.']]],
            ['type' => 'list', 'items' => [[['text' => 'Soft close']], [['text' => 'Tool-free']]]],
        ]];
    }

    it('keeps paragraphs, headings, lists and bold, and gives the words alone for search', function () {
        $text = StructuredText::of('description_en', catalogValuesDocument(), 1000);

        expect($text->toArray())->toBe(catalogValuesDocument())
            ->and($text->plain())->toBe("Fitting\nFits every cabinet.\nSoft close\nTool-free");
    });

    it('reads back the same value whatever order its keys come in', function () {
        // PostgreSQL's jsonb sorts an object's keys when it stores them (lesson 38).
        $stored = '{"blocks": [{"runs": [{"bold": true, "text": "Strong"}], "type": "paragraph"}, {"items": [[{"text": "One"}]], "type": "list"}]}';

        expect(StructuredText::fromJson('terms_en', $stored, 100)->toArray())->toBe(['blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Strong', 'bold' => true]]],
            ['type' => 'list', 'items' => [[['text' => 'One']]]],
        ]]);
    });

    it('counts a bold change as a change', function () {
        $plain = StructuredText::of('description_en', ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Strong']]]]], 100);
        $bold = StructuredText::of('description_en', ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Strong', 'bold' => true]]]]], 100);

        expect($plain->equals($bold))->toBeFalse()->and($plain->toJson())->not->toBe($bold->toJson());
    });

    it('refuses anything but its shape, and HTML is only ever text', function (mixed $document, string $reason) {
        expect(fn () => StructuredText::of('description_en', $document, 20))->toThrow(InvalidCatalogAttribute::class, $reason);
    })->with([
        'not a document' => ['<p>Hi</p>', 'a list of blocks'],
        'no blocks' => [['blocks' => []], 'required'],
        'an unknown block' => [['blocks' => [['type' => 'table', 'runs' => []]]], 'a paragraph, a heading or a list'],
        'an extra key' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'a']], 'style' => 'x']]], 'holds runs only'],
        'a run with a link' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'a', 'href' => 'x']]]]], 'its text, and whether it is bold'],
        'a newline in a run' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => "a\nb"]]]]], 'one line'],
        'bold not yes or no' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'a', 'bold' => 'yes']]]]], 'yes or no'],
        'an empty list' => [['blocks' => [['type' => 'list', 'items' => []]]], 'holds items'],
        'too long' => [['blocks' => [['type' => 'paragraph', 'runs' => [['text' => str_repeat('a', 21)]]]]], 'at most 20'],
    ]);

    it('keeps markup typed into a run as plain words', function () {
        $text = StructuredText::of('description_en', ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '<script>x</script>']]]]], 100);

        expect($text->plain())->toBe('<script>x</script>');
    });
});

describe('labels', function () {
    it('takes one or two words, in each language', function () {
        $label = Label::add('01j8z3k4m5n6p7q8r9s0t1v2w3', LocalizedName::of('عرض خاص', 'Best Seller', Label::NAME_MAX), LabelTone::Healthy, 1);

        expect($label->name()->en)->toBe('Best Seller');
    });

    it('refuses three words in either language (amendment 1(f))', function (string $ar, string $en, string $attribute) {
        expect(fn () => Label::add('01j8z3k4m5n6p7q8r9s0t1v2w3', LocalizedName::of($ar, $en, Label::NAME_MAX), LabelTone::Neutral, 0))
            ->toThrow(InvalidCatalogAttribute::class, $attribute);
    })->with([
        'English' => ['جديد', 'Limited Time Offer', 'name_en'],
        'Arabic' => ['عرض لفترة محدودة', 'Limited', 'name_ar'],
    ]);

    it('offers exactly the Badge\'s ten tones', function () {
        expect(array_map(static fn (LabelTone $tone): string => $tone->value, LabelTone::cases()))->toEqualCanonicalizing([
            'gray', 'blue', 'green', 'amber', 'red', 'gray-subtle', 'blue-subtle', 'green-subtle', 'amber-subtle', 'red-subtle',
        ]);
    });
});

describe('word pairs', function () {
    it('keeps a pair normalised and in order, whichever way it was typed', function () {
        $one = WordPair::add('01j8z3k4m5n6p7q8r9s0t1v2w3', 'Hinge', 'مفصّلة');
        $other = WordPair::add('01j8z3k4m5n6p7q8r9s0t1v2w4', 'مفصلة', 'hinge');

        expect([$one->wordA, $one->wordB])->toBe([$other->wordA, $other->wordB])
            ->and($one->wordA)->toBe('hinge');
    });

    it('never pairs a word with itself, however it is spelt', function () {
        expect(fn () => WordPair::add('01j8z3k4m5n6p7q8r9s0t1v2w3', 'مدرسة', 'مدرسه'))->toThrow(InvalidCatalogAttribute::class, 'word_b');
    });
});

describe('a warranty period', function () {
    it('is 1 to 600 months, or for life', function (?int $months, bool $ok) {
        $ok ? expect(WarrantyPeriod::of($months)->months)->toBe($months) : expect(fn () => WarrantyPeriod::of($months))->toThrow(InvalidCatalogAttribute::class);
    })->with([
        'for life' => [null, true],
        'one month' => [1, true],
        'fifty years' => [600, true],
        'none' => [0, false],
        'too long' => [601, false],
    ]);
});
