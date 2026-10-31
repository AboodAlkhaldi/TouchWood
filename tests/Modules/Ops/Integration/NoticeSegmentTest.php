<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNotice;
use Modules\Ops\Application\Command\ChangeNotice\ChangeNoticeHandler;
use Modules\Ops\Application\Command\CreateNotice\CreateNotice;
use Modules\Ops\Application\Command\CreateNotice\CreateNoticeHandler;
use Modules\Ops\Application\Query\ViewNotice\ViewNotice;
use Modules\Ops\Application\Query\ViewNotice\ViewNoticeHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembers;
use Modules\Promotions\Application\Command\SetSegmentMembers\SetSegmentMembersHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| An offer notice to one of the store's Promotions segments (ops.md §1.7): the segment read through the
| store-scoped `PromotionsApi::segmentMembers` - another store's refused as `SegmentNotFound`, an empty one
| as an empty audience - and sent under the same rules as "every customer": the bell to every member, the
| email only to those who may receive offers (§1.3). The queue is `sync`.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
    CapturedEmails::install();
});

/**
 * A hand-picked segment of the store, with these members, made by a marketer of every store; the admin
 * acting afterwards is set by the test.
 *
 * @param  list<string>  $members
 */
function noticeSegment(array $members, string $storeCode = 'sa'): string
{
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa', 'ae']));
    $segment = app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId($storeCode), 'شريحة', 'Picked', 'STATIC', 'INDIVIDUAL'));

    if ($members !== []) {
        app(SetSegmentMembersHandler::class)->handle(new SetSegmentMembers($segment, $members));
    }

    return $segment;
}

function noticeSegmentCreate(?string $segment, string $storeCode = 'sa', ?string $sendAt = null): string
{
    return app(CreateNoticeHandler::class)->handle(new CreateNotice(
        Fx::storeId($storeCode),
        'عروض رمضان بدأت',
        'Ramadan offers have started',
        'خصم على كل الكراسي.',
        'A discount on every chair.',
        null,
        true,
        true,
        $sendAt,
        $segment,
    ));
}

/** @return list<string> */
function noticeSegmentBells(string $noticeKind = 'offer_notice'): array
{
    $ids = array_values(array_map(strval(...), DB::table('ops.inbox_entries')->where('kind', $noticeKind)->orderBy('customer_id')->pluck('customer_id')->all()));
    sort($ids);

    return $ids;
}

it('sends to the segment\'s members only: the bell to each, the email only to those who agreed', function () {
    $agreeing = Wx::agreeing('sara@example.test');
    $notAgreeing = Fx::customer('omar@example.test');
    $outside = Wx::agreeing('lina@example.test');
    $segment = noticeSegment([$agreeing, $notAgreeing]);
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);

    $id = noticeSegmentCreate($segment);

    $members = [$agreeing, $notAgreeing];
    sort($members);
    $row = DB::table('ops.notices')->where('id', $id)->first();
    expect(noticeSegmentBells())->toBe($members)
        ->and(noticeSegmentBells())->not->toContain($outside)
        ->and(CapturedEmails::installed()->to())->toBe(['sara@example.test'])
        ->and([$row?->status, $row?->segment_id, $row?->cursor_type, (int) $row?->bell_count])->toBe(['SENT', $segment, 'SEGMENT', 2])
        ->and(app(ViewNoticeHandler::class)->handle(new ViewNotice($id))->segmentId)->toBe($segment);
});

it('refuses another store\'s segment as an unknown one, and an empty segment as an empty audience', function () {
    $sara = Wx::agreeing('sara@example.test');
    $theirs = noticeSegment([], 'ae');
    $empty = noticeSegment([]);
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);

    expect(fn () => noticeSegmentCreate($theirs))->toThrow(SegmentNotFound::class)
        ->and(fn () => noticeSegmentCreate($empty))->toThrow(InvalidOpsInput::class)
        ->and(DB::table('ops.notices')->count())->toBe(0)
        ->and(noticeSegmentBells())->toBe([])
        ->and($sara)->toBeString();
});

it('changes a waiting notice\'s audience to a segment, or back to every customer of the store', function () {
    $sara = Wx::agreeing('sara@example.test');
    $omar = Wx::agreeing('omar@example.test');
    $segment = noticeSegment([$sara]);
    $theirs = noticeSegment([], 'ae');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $id = noticeSegmentCreate(null, sendAt: now()->addDay()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'));
    $change = fn (?string $to, ?string $sendAt = null) => app(ChangeNoticeHandler::class)->handle(new ChangeNotice($id, 'عروض', 'Offers', 'خصم.', 'A discount.', null, true, true, $sendAt ?? now()->addDay()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'), $to));

    $change($segment);
    expect(DB::table('ops.notices')->where('id', $id)->value('segment_id'))->toBe($segment)
        ->and(fn () => $change($theirs))->toThrow(SegmentNotFound::class);

    $change(null);
    expect(DB::table('ops.notices')->where('id', $id)->value('segment_id'))->toBeNull();

    $change($segment, '');
    expect(noticeSegmentBells())->toBe([$sara])
        ->and($omar)->toBeString();
});

it('keeps a segment cursor only on a notice that names a segment, in the database too', function () {
    $sara = Wx::agreeing('sara@example.test');
    Fx::actAsAdmin(['sa'], ['ops.notice.manage']);
    $id = noticeSegmentCreate(null);

    expect(fn () => DB::table('ops.notices')->where('id', $id)->update(['cursor_type' => 'SEGMENT']))->toThrow(QueryException::class, 'notices_segment_cursor')
        ->and($sara)->toBeString();
});
