<?php

declare(strict_types=1);

use Modules\Ops\Domain\ValueObject\WrittenText;

// Every digit is saved 0-9, on Arabic pages too (owner, 2026-10-06): what staff write reaches bells and emails as kept.
it('keeps every digit of what staff write as 0-9, in both languages', function () {
    $text = WrittenText::of('خصم ٢٠٪', 'Sale ۲۰%', 'حتى ٣٠ يومًا', 'For ۳۰ days');

    expect([$text->titleAr, $text->titleEn, $text->bodyAr, $text->bodyEn])->toBe(['خصم 20٪', 'Sale 20%', 'حتى 30 يومًا', 'For 30 days']);
});
