<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\ScheduleState;
use Modules\Content\Domain\ValueObject\Schedule;
use Modules\Content\Domain\ValueObject\Slug;
use Modules\Content\Domain\ValueObject\Words;

/*
| Content's small values (content.md §1.1 rules 2, 3, 5, 15, 16; §4.1): words of one line with their digits 0-9, a
| pair's Arabic before its English, an address made from a title and cut at 200, a schedule's state from the clock.
*/

function contentRule(callable $work): string
{
    try {
        $work();
    } catch (InvalidContentInput $refused) {
        return $refused->attribute.':'.$refused->rule;
    } catch (MissingEnglish $missing) {
        return $missing->attribute.':english';
    }

    throw new LogicException('Not refused.');
}

describe('words', function () {
    it('keeps one line, trimmed, its Arabic-Indic digits saved 0-9, an empty box as none', function () {
        expect(Words::line('name', '  عرض ٢٠٢٧ ۳ ', 40))->toBe('عرض 2027 3')
            ->and(Words::line('name', '   ', 40))->toBeNull()
            ->and(Words::line('name', null, 40))->toBeNull();
    });

    it('refuses a line break, a long line and bytes that are not UTF-8', function () {
        expect(contentRule(fn () => Words::line('name', "a\nb", 40)))->toBe('name:one_line')
            ->and(contentRule(fn () => Words::line('name', str_repeat('ب', 41), 40)))->toBe('name:too_long')
            ->and(contentRule(fn () => Words::line('name', "\xC3\x28", 40)))->toBe('name:format');
    });

    it('§1.1 rule 2: refuses English beside an empty Arabic, and asks for English where both are needed', function () {
        expect(contentRule(fn () => Words::pair('title', null, 'Title', 80)))->toBe('title_ar:required')
            ->and(Words::pair('title', 'عنوان', null, 80))->toBe(['ar' => 'عنوان', 'en' => null])
            ->and(contentRule(fn () => Words::both('title', 'عنوان', null, 80)))->toBe('title:english')
            ->and(contentRule(fn () => Words::arabic('title', null, null, 80)))->toBe('title_ar:required')
            ->and(Words::bothOrNone('about', null, null, 80))->toBe(['ar' => null, 'en' => null]);
    });

    it('§1.1 rule 16: takes a shop page or an https:// address, and nothing else', function (string $link, ?string $kept) {
        $kept === null
            ? expect(contentRule(fn () => Words::link('link', $link)))->toBe('link:link')
            : expect(Words::link('link', $link))->toBe($kept);
    })->with([
        'a shop page' => ['/categories/chairs', '/categories/chairs'],
        'a page without its slash' => ['pages/about', '/pages/about'],
        'a full address' => ['https://example.com/x', 'https://example.com/x'],
        'javascript' => ['javascript:alert(1)', null],
        'another site by //' => ['//evil.example', null],
        'a backslash' => ['/a\\b', null],
        'plain http' => ['http://example.com', null],
    ]);
});

describe('addresses', function () {
    it('§1.1 rule 15: makes an Arabic address of Arabic letters and digits, and an English one of Latin', function () {
        expect(Slug::fromTitle('slug_ar', 'ar', 'دليلُ التركيب ٢ (Blum)')->value)->toBe('دليل-التركيب-2')
            ->and(Slug::fromTitle('slug_en', 'en', 'Häfele: Fitting Guide 2')->value)->toBe('hafele-fitting-guide-2');
    });

    it('cuts a long title\'s address at 200 characters, before the database\'s check could refuse it', function () {
        $slug = Slug::fromTitle('slug_ar', 'ar', str_repeat('مطبخ ', 80));

        expect(mb_strlen($slug->value))->toBeLessThanOrEqual(200)
            ->and($slug->value)->not->toEndWith('-');
    });

    it('holds a typed address to its language\'s letters, and a title with none to a typed one', function () {
        expect(Slug::of('slug_en', 'en', 'Fitting-Guide')->value)->toBe('fitting-guide')
            ->and(contentRule(fn () => Slug::of('slug_ar', 'ar', 'guide')))->toBe('slug_ar:slug_ar')
            ->and(contentRule(fn () => Slug::of('slug_en', 'en', 'a/b')))->toBe('slug_en:slug_en')
            ->and(contentRule(fn () => Slug::fromTitle('slug_en', 'en', '!!!')))->toBe('slug_en:slug_from_title');
    });
});

describe('schedules', function () {
    it('§4.1: reads Draft, Scheduled, Live and Ended from the dates and the clock, a draft whatever its dates', function () {
        $now = CarbonImmutable::parse('2027-02-18 12:00:00')->toDateTimeImmutable();
        $before = $now->modify('-1 hour');
        $after = $now->modify('+1 hour');

        expect((new Schedule(false, $before, null))->state($now))->toBe(ScheduleState::Draft)
            ->and((new Schedule(true, $after, null))->state($now))->toBe(ScheduleState::Scheduled)
            ->and((new Schedule(true, $before, $after))->state($now))->toBe(ScheduleState::Live)
            ->and((new Schedule(true, $now, null))->state($now))->toBe(ScheduleState::Live)
            ->and((new Schedule(true, $before, $now))->state($now))->toBe(ScheduleState::Ended);
    });

    it('§1.10: two scheduled share a moment when each starts before the other ends; a draft never does', function () {
        $at = static fn (string $time): DateTimeImmutable => CarbonImmutable::parse("2027-02-18 {$time}")->toDateTimeImmutable();

        expect((new Schedule(true, $at('10:00'), $at('12:00')))->overlaps(new Schedule(true, $at('11:00'), null)))->toBeTrue()
            ->and((new Schedule(true, $at('10:00'), $at('12:00')))->overlaps(new Schedule(true, $at('12:00'), null)))->toBeFalse()
            ->and((new Schedule(true, $at('10:00'), null))->overlaps(new Schedule(true, $at('09:00'), $at('10:01'))))->toBeTrue()
            ->and((new Schedule(false, $at('10:00'), null))->overlaps(new Schedule(true, $at('10:00'), null)))->toBeFalse();
    });
});
