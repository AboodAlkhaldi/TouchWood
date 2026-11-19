<?php

declare(strict_types=1);

/*
| The one layout of every email Ops sends (flags-2026-11.md S5, F8-F10): the navy band with the logo and the
| store's name, the copper line, the button with its address under it, the cream footer, no contact lines.
*/

/** @return array<string, string> */
function emailLayoutShared(string $locale): array
{
    return [
        'locale' => $locale,
        'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
        'brand' => 'Saudi Arabia',
        'logo' => 'https://shop.test/icons/email-logo.png',
    ];
}

function emailLayoutLooksShared(string $html, bool $button): void
{
    expect($html)->toContain('background-color: #02365e')
        ->and($html)->toContain('<img src="https://shop.test/icons/email-logo.png" width="40" height="40" alt="Saudi Arabia"')
        ->and($html)->toContain('>Saudi Arabia</td>')
        ->and($html)->toContain('background-color: #dca071; height: 3px')
        ->and($html)->toContain('background-color: #f6efe8')
        ->and($html)->toContain('Sent by Saudi Arabia');
    expect($html)->not->toContain('#1f2937');
    expect($html)->not->toContain('width="96"');

    if ($button) {
        expect($html)->toContain('<a href="https://shop.test/go" style="display: inline-block')
            ->and($html)->toContain('>Open it</a>')
            ->and($html)->toContain('dir="ltr">https://shop.test/go</p>');
    }
}

it('draws the event email in the shared look', function () {
    emailLayoutLooksShared(view('ops::mail.event', [...emailLayoutShared('en'), 'lines' => ['Hi'], 'summary' => [['label' => 'Items', 'value' => '10'], ['label' => 'Total', 'value' => '12']],
        'action' => 'Open it', 'link' => 'https://shop.test/go', 'trackLink' => null, 'trackAction' => null])->render(), true);
});

it('draws the written email in the shared look', function () {
    emailLayoutLooksShared(view('ops::mail.written', [...emailLayoutShared('en'), 'lines' => ['Hi'], 'action' => 'Open it', 'link' => 'https://shop.test/go', 'leave' => null, 'unsubscribe' => null])->render(), true);
});

it('draws the security email in the shared look', function () {
    emailLayoutLooksShared(view('ops::mail.security', [...emailLayoutShared('en'), 'lines' => ['Hi'], 'action' => 'Open it', 'link' => 'https://shop.test/go'])->render(), true);
});

it('draws the daily report email in the shared look', function () {
    emailLayoutLooksShared(view('ops::daily-report-email', [...emailLayoutShared('en'), 'lines' => ['Hi']])->render(), false);
});

it('boxes the summary with its total in bold and adds the tracking button', function () {
    $html = view('ops::mail.event', [...emailLayoutShared('en'), 'lines' => ['Hi'], 'summary' => [['label' => 'Items', 'value' => '10'], ['label' => 'Total', 'value' => '12']],
        'action' => 'Open it', 'link' => 'https://shop.test/go', 'trackLink' => 'https://carrier.test/t/1', 'trackAction' => 'Track it'])->render();

    expect($html)->toContain('border: 1px solid #dcdfe1; border-radius: 8px')
        ->and($html)->toContain('<td style="font-weight: bold;">Total</td>')
        ->and($html)->toContain('<a href="https://carrier.test/t/1" style="display: inline-block')
        ->and($html)->toContain('dir="ltr">https://carrier.test/t/1</p>')
        ->and($html)->toContain('color: #02365e; text-decoration: none;');
    expect($html)->not->toContain('<td style="font-weight: bold;">Items</td>');
});

it('keeps the direction and says "sent by" in Arabic', function () {
    $html = view('ops::daily-report-email', [...emailLayoutShared('ar'), 'lines' => ['مرحبا']])->render();

    expect($html)->toContain('lang="ar" dir="rtl"')->toContain('أُرسلت من Saudi Arabia');
});
