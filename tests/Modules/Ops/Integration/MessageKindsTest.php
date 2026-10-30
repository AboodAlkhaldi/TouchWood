<?php

declare(strict_types=1);

use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;

/*
| The message kinds (ops.md §1.5, §1.6): every row of the spec once, with the channels it fixes, and
| its wording in Arabic and English (§1.1) - the bell's title and line, the email's subject and lines,
| and the SMS where a kind has one. C18's words are in security.php, checked with the security messages.
| Step 7's three (§1.7-§1.9): a notice and a staff message, written by staff, and the newsletter's
| confirmation, an email with no bell.
*/

it('lists every row of §1.5 and §1.6, once', function () {
    $codes = array_map(static fn (MessageKind $kind): string => $kind->code(), MessageKind::cases());

    expect($codes)->toBe([
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C6b', 'C7', 'C8', 'C8', 'C8b', 'C9', 'C9b', 'C10', 'C11', 'C12', 'C13', 'C14', 'C15', 'C16', 'C17', 'C17b', 'C18',
        'S1', 'S2', 'S3', 'S4', 'S5', 'S5b', 'S6', 'S7', 'S8', 'S9',
        '§1.7', '§1.8', '§1.9',
    ]);
});

it('fixes each kind\'s channels as the spec does', function () {
    foreach (MessageKind::cases() as $kind) {
        $expected = match ($kind) {
            // Codes by SMS, links by email, no bell entry (§1.5 C18).
            MessageKind::Security => [MessageChannel::Email, MessageChannel::Sms],
            // The critical SMS to super admins (§1.6; §9.3 #12).
            MessageKind::GatewayKeyExpiring, MessageKind::GatewayKeyRejected => [MessageChannel::Bell, MessageChannel::Email, MessageChannel::Sms],
            // A guest's confirmation: no bell (§1.9).
            MessageKind::NewsletterConfirmation => [MessageChannel::Email],
            default => [MessageChannel::Bell, MessageChannel::Email],
        };

        expect($kind->channels())->toBe($expected, $kind->value)
            ->and($kind->isForStaff())->toBe(str_starts_with($kind->code(), 'S'), $kind->value);
    }
});

it('words every kind in Arabic and English, with the same placeholders in both', function (string $locale) {
    $other = $locale === 'ar' ? 'en' : 'ar';

    foreach (MessageKind::cases() as $kind) {
        if ($kind === MessageKind::Security) {
            continue;
        }

        $words = trans("ops::messages.{$kind->value}", [], $locale);
        $theirs = trans("ops::messages.{$kind->value}", [], $other);
        expect($words)->toBeArray("{$kind->value} has no {$locale} words");
        assert(is_array($words) && is_array($theirs));

        // The bell's title and line only where the kind has a bell.
        foreach ($kind->hasBell() ? ['title', 'line', 'subject'] : ['subject'] as $key) {
            $text = $words[$key] ?? null;
            expect(is_string($text) && trim($text) !== '')->toBeTrue("{$kind->value}.{$key} ({$locale})");
        }

        $lines = $words['lines'] ?? null;
        expect(is_array($lines) && $lines !== [])->toBeTrue("{$kind->value}.lines ({$locale})");
        expect(isset($words['sms']))->toBe(in_array(MessageChannel::Sms, $kind->channels(), true), "{$kind->value}: an SMS text only where the kind sends one");

        preg_match_all('/:([a-z_]+)/', (string) json_encode($words, JSON_UNESCAPED_UNICODE), $mine);
        preg_match_all('/:([a-z_]+)/', (string) json_encode($theirs, JSON_UNESCAPED_UNICODE), $those);
        $mine = array_values(array_unique($mine[1]));
        $those = array_values(array_unique($those[1]));
        sort($mine);
        sort($those);

        expect($mine)->toBe($those, "{$kind->value}: the same placeholders in Arabic and English");
    }
})->with(['ar', 'en']);
