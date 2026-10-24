<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Feedback\Application\Command\AddRejectReason\AddRejectReason;
use Modules\Feedback\Application\Command\AddRejectReason\AddRejectReasonHandler;
use Modules\Feedback\Application\Command\ChangeRejectReason\ChangeRejectReason;
use Modules\Feedback\Application\Command\ChangeRejectReason\ChangeRejectReasonHandler;
use Modules\Feedback\Application\Command\OrderRejectReasons\OrderRejectReasons;
use Modules\Feedback\Application\Command\OrderRejectReasons\OrderRejectReasonsHandler;
use Modules\Feedback\Application\Command\RemoveRejectReason\RemoveRejectReason;
use Modules\Feedback\Application\Command\RemoveRejectReason\RemoveRejectReasonHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\ListRejectReasons\ListRejectReasons;
use Modules\Feedback\Application\Query\ListRejectReasons\ListRejectReasonsHandler;
use Modules\Feedback\Application\Query\ListRejectReasons\RejectReasonRow;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\RejectReasonNotFound;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| The ready reasons (feedback.md §2.4 B4; owner, 2026-10-24): one list for every store, in Arabic and English,
| the same for reviews and questions, in the order admins give it — changed only by an admin who holds
| `feedback.settings.update` in All stores, audited; read by every moderator and answerer.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function reasonsAdmin(): void
{
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::SETTINGS_UPDATE], ['*'], RoleLevel::Admin));
}

function reasonsAdd(string $en, string $ar = 'سبب'): string
{
    return app(AddRejectReasonHandler::class)->handle(new AddRejectReason($ar, $en));
}

/** @return list<array{string, string, int}> the list as read: English, Arabic, place */
function reasonsRead(): array
{
    return array_map(fn (RejectReasonRow $row): array => [$row->textEn, $row->textAr, $row->position], app(ListRejectReasonsHandler::class)->handle(new ListRejectReasons));
}

it('adds reasons at the end, changes and removes them, the rest moving up, each audited', function () {
    reasonsAdmin();
    $off = reasonsAdd('Off topic', 'خارج الموضوع');
    $rude = reasonsAdd('Rude words', 'ألفاظ غير لائقة');
    $other = reasonsAdd('Not about the product', 'لا يخص المنتج');

    app(ChangeRejectReasonHandler::class)->handle(new ChangeRejectReason(strtoupper($rude), 'ألفاظ مسيئة', "  Offensive\r\nwords "));
    app(RemoveRejectReasonHandler::class)->handle(new RemoveRejectReason($off));

    expect(reasonsRead())->toBe([["Offensive\nwords", 'ألفاظ مسيئة', 1], ['Not about the product', 'لا يخص المنتج', 2]])
        ->and(Fx::audits('feedback.reject_reason.added'))->toBe(3)
        ->and(Fx::audits('feedback.reject_reason.changed', $rude))->toBe(1)
        ->and(Fx::audits('feedback.reject_reason.removed', $off))->toBe(1)
        ->and(DB::table('platform.audit_entries')->where('subject_id', $other)->value('store_id'))->toBeNull();
});

it('puts the list in a new order, auditing each reason that moved', function () {
    reasonsAdmin();
    $ids = [reasonsAdd('One'), reasonsAdd('Two'), reasonsAdd('Three')];

    app(OrderRejectReasonsHandler::class)->handle(new OrderRejectReasons([$ids[2], $ids[0], $ids[1]]));

    expect(array_column(reasonsRead(), 0))->toBe(['Three', 'One', 'Two'])
        ->and(Fx::audits('feedback.reject_reason.ordered'))->toBe(3);

    // Two swapped, one left where it stands: only the two moved are audited.
    app(OrderRejectReasonsHandler::class)->handle(new OrderRejectReasons([$ids[2], $ids[1], $ids[0]]));

    expect(array_column(reasonsRead(), 0))->toBe(['Three', 'Two', 'One'])
        ->and(Fx::audits('feedback.reject_reason.ordered'))->toBe(5)
        ->and(Fx::audits('feedback.reject_reason.ordered', $ids[2]))->toBe(1)
        ->and(Fb::thrown(fn () => app(OrderRejectReasonsHandler::class)->handle(new OrderRejectReasons([$ids[0], $ids[1]])), InvalidFeedbackAttribute::class)->attribute)->toBe('reasons')
        ->and(Fb::thrown(fn () => app(OrderRejectReasonsHandler::class)->handle(new OrderRejectReasons([...$ids, $ids[0]])), InvalidFeedbackAttribute::class)->attribute)->toBe('reasons');
});

it('changes nothing and records nothing when the words are the same', function () {
    reasonsAdmin();
    $id = reasonsAdd('Off topic');

    app(ChangeRejectReasonHandler::class)->handle(new ChangeRejectReason($id, 'سبب', 'Off topic'));

    expect(Fx::audits('feedback.reject_reason.changed'))->toBe(0);
});

it('refuses empty or long words, and a reason that is not there', function () {
    reasonsAdmin();
    $id = reasonsAdd('Off topic');

    expect(Fb::thrown(fn () => reasonsAdd(' '), InvalidFeedbackAttribute::class)->attribute)->toBe('text_en')
        ->and(Fb::thrown(fn () => reasonsAdd('Fine', str_repeat('ب', 501)), InvalidFeedbackAttribute::class)->attribute)->toBe('text_ar')
        ->and(fn () => app(RemoveRejectReasonHandler::class)->handle(new RemoveRejectReason(strtolower((string) Str::ulid()))))->toThrow(RejectReasonNotFound::class)
        ->and(fn () => app(ChangeRejectReasonHandler::class)->handle(new ChangeRejectReason('reason-1', 'a', 'b')))->toThrow(InvalidFeedbackAttribute::class)
        ->and(array_column(reasonsRead(), 2))->toBe([1])
        ->and($id)->toBeString();
});

it('lets only an admin of every store change the list', function (Closure $actAs) {
    $actAs();

    expect(fn () => reasonsAdd('Off topic'))->toThrow(Unauthorized::class)
        ->and(DB::table('feedback.reject_reasons')->count())->toBe(0);
})->with([
    'an admin of one store' => [fn () => Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin))],
    'an admin of every store, named one by one (not All stores)' => [fn () => Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::SETTINGS_UPDATE], array_values(array_map('strval', DB::table('platform.stores')->pluck('code')->all())), RoleLevel::Admin))],
    'a moderator' => [fn () => Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa', 'eg']))],
    'a customer' => [fn () => Fx::actAsCustomer(Fx::customer('shopper@example.test'))],
]);

it('lets every moderator and answerer read the list, and nobody else', function () {
    reasonsAdmin();
    reasonsAdd('Off topic');

    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['eg']));
    $moderator = reasonsRead();
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['sa']));
    $answerer = reasonsRead();
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::WISHLIST_VIEW], ['sa']));

    expect([$moderator, $answerer])->toBe([[['Off topic', 'سبب', 1]], [['Off topic', 'سبب', 1]]])
        ->and(fn () => reasonsRead())->toThrow(Unauthorized::class);
});

it('keeps both languages and a place from 1 in the table', function (array $row, string $constraint) {
    expect(fn () => DB::table('feedback.reject_reasons')->insert(['id' => strtolower((string) Str::ulid()), 'created_at' => now(), 'updated_at' => now(), ...$row]))
        ->toThrow(QueryException::class, '"'.$constraint.'"');
})->with([
    'no Arabic' => [['text_ar' => ' ', 'text_en' => 'Off', 'position' => 1], 'reject_reasons_text'],
    'place 0' => [['text_ar' => 'سبب', 'text_en' => 'Off', 'position' => 0], 'reject_reasons_position'],
]);
