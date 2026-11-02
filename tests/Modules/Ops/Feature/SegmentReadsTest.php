<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;

use function Pest\Laravel\seed;

/*
| The segments on Ops' forms (ops.md §4.3, §4.4, on `PromotionsApi::storeSegments` and `storeSegment`, #313):
| the list each form picks from, under the form's own permission in the store, and the segment's name on a
| notice and on a staff message, null once it was deleted.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CapturedEmails::install();
    CapturedNewsletterList::install();
});

const SEGMENT_READS_JSON = ['HTTP_ACCEPT' => 'application/json'];

function segmentReadsStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * A segment written straight in its table: acting as someone here would be who every request is.
 *
 * @param  list<string>  $members
 */
function segmentReadsSegment(string $storeCode, string $nameEn, array $members = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('promotions.segments')->insert(['id' => $id, 'store_id' => Fx::storeId($storeCode), 'name_ar' => 'شريحة '.$nameEn, 'name_en' => $nameEn, 'type' => 'STATIC', 'audience' => 'INDIVIDUAL', 'created_at' => now(), 'updated_at' => now()]);

    foreach ($members as $member) {
        DB::table('promotions.segment_members')->insert(['segment_id' => $id, 'customer_id' => $member, 'added_at' => now()]);
    }

    return $id;
}

function segmentReadsNotice(string $storeCode, string $writer, ?string $segment): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.notices')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false,
        'status' => 'SENT', 'send_at' => now(), 'created_by' => $writer, 'created_at' => now(), 'updated_at' => now(), 'started_at' => now(), 'finished_at' => now(), 'segment_id' => $segment,
    ]);

    return $id;
}

function segmentReadsMessage(string $storeCode, string $sender, ?string $segment): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.staff_messages')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'sender_id' => $sender, 'audience' => $segment === null ? 'STORE' : 'SEGMENT', 'segment_id' => $segment,
        'title_ar' => 'ع', 'title_en' => 'T', 'body_ar' => 'ن', 'body_en' => 'B', 'by_bell' => true, 'by_email' => false, 'status' => 'SENT', 'created_at' => now(), 'finished_at' => now(),
    ]);

    return $id;
}

describe('the list a form picks from', function () {
    it('gives a store\'s segments, newest first, each with its names and how many it holds', function (string $address, string $permission) {
        $old = segmentReadsSegment('sa', 'Old', [Fx::customer('one@example.test'), Fx::customer('two@example.test')]);
        $new = segmentReadsSegment('sa', 'New');
        segmentReadsSegment('ae', 'Theirs');
        $browser = segmentReadsStaff(Fx::staffWith([$permission], ['sa'], RoleLevel::Admin));

        $browser->get("{$address}?store=sa", SEGMENT_READS_JSON)->assertOk()->assertExactJson([
            'segments' => [
                ['id' => $new, 'nameAr' => 'شريحة New', 'nameEn' => 'New', 'memberCount' => 0],
                ['id' => $old, 'nameAr' => 'شريحة Old', 'nameEn' => 'Old', 'memberCount' => 2],
            ],
            'next' => null,
        ]);
    })->with([
        'the notice form' => ['/admin/notices/segments', 'ops.notice.manage'],
        'the composer' => ['/admin/messages/segments', 'ops.message.send_many'],
    ]);

    it('pages by the id of the last row, twenty at a time', function () {
        $ids = [];

        for ($i = 0; $i < 21; $i++) {
            $ids[] = segmentReadsSegment('sa', 'S'.$i);
        }

        $browser = segmentReadsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
        $first = $browser->get('/admin/notices/segments?store=sa', SEGMENT_READS_JSON)->assertOk();

        expect($first->json('segments'))->toHaveCount(20)
            ->and($first->json('next'))->toBe($ids[1]);

        $second = $browser->get('/admin/notices/segments?store=sa&after='.$first->json('next'), SEGMENT_READS_JSON)->assertOk();

        expect(array_column((array) $second->json('segments'), 'id'))->toBe([$ids[0]])
            ->and($second->json('next'))->toBeNull();
    });

    it('refuses a cursor that is no id as any list of Ops does', function () {
        $browser = segmentReadsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));

        $browser->get('/admin/notices/segments?store=sa&after=nope', SEGMENT_READS_JSON)->assertUnprocessable()->assertJsonPath('type', 'ops.invalid_ops_input');
    });

    it('refuses the wrong permission, another store, and each form\'s permission on the other form', function () {
        segmentReadsSegment('sa', 'Ours');
        $notices = segmentReadsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
        $notices->get('/admin/notices/segments?store=sa', SEGMENT_READS_JSON)->assertOk();
        $notices->get('/admin/notices/segments?store=ae', SEGMENT_READS_JSON)->assertForbidden();
        $notices->get('/admin/messages/segments?store=sa', SEGMENT_READS_JSON)->assertForbidden();

        $many = segmentReadsStaff(Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin));
        $many->get('/admin/messages/segments?store=sa', SEGMENT_READS_JSON)->assertOk();
        $many->get('/admin/messages/segments?store=ae', SEGMENT_READS_JSON)->assertForbidden();
        $many->get('/admin/notices/segments?store=sa', SEGMENT_READS_JSON)->assertForbidden();

        // Sending to one customer is not sending to many.
        $one = segmentReadsStaff(Fx::staffWith(['ops.message.send_one'], ['sa']));
        $one->get('/admin/messages/segments?store=sa', SEGMENT_READS_JSON)->assertForbidden();
    });

    it('is not taken for a notice by the notice\'s own route', function () {
        $browser = segmentReadsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));

        $browser->get('/admin/notices/segments?store=sa', SEGMENT_READS_JSON)->assertOk()->assertJsonStructure(['segments', 'next']);
    });
});

describe('the segment on what was written', function () {
    it('names a notice\'s segment, null for every customer, and null with its id when deleted', function () {
        $writer = Fx::staffWith(['ops.notice.manage'], ['sa']);
        $held = segmentReadsSegment('sa', 'Kept', [Fx::customer('one@example.test')]);
        $gone = segmentReadsSegment('sa', 'Gone');
        $kept = segmentReadsNotice('sa', $writer, $held);
        $deleted = segmentReadsNotice('sa', $writer, $gone);
        $everyone = segmentReadsNotice('sa', $writer, null);
        DB::table('promotions.segments')->where('id', $gone)->delete();
        $browser = segmentReadsStaff($writer);

        $list = collect((array) $browser->get('/admin/notices?store=sa', SEGMENT_READS_JSON)->assertOk()->json('notices'))->keyBy('id');

        expect($list[$kept])->toMatchArray(['segmentId' => $held, 'segmentNameAr' => 'شريحة Kept', 'segmentNameEn' => 'Kept', 'segmentMemberCount' => 1])
            ->and($list[$deleted])->toMatchArray(['segmentId' => $gone, 'segmentNameAr' => null, 'segmentNameEn' => null, 'segmentMemberCount' => null])
            ->and($list[$everyone])->toMatchArray(['segmentId' => null, 'segmentNameAr' => null, 'segmentNameEn' => null, 'segmentMemberCount' => null]);

        $browser->get("/admin/notices/{$kept}", SEGMENT_READS_JSON)->assertOk()
            ->assertJsonPath('segmentNameEn', 'Kept')
            ->assertJsonPath('segmentMemberCount', 1);
        $browser->get("/admin/notices/{$deleted}", SEGMENT_READS_JSON)->assertOk()
            ->assertJsonPath('segmentId', $gone)
            ->assertJsonPath('segmentNameEn', null);
    });

    it('names a staff message\'s segment, and null with its id when deleted', function () {
        $sender = Fx::staffWith(['ops.message.send_many'], ['sa'], RoleLevel::Admin);
        $held = segmentReadsSegment('sa', 'Kept', [Fx::customer('one@example.test')]);
        $gone = segmentReadsSegment('sa', 'Gone');
        $kept = segmentReadsMessage('sa', $sender, $held);
        $deleted = segmentReadsMessage('sa', $sender, $gone);
        $store = segmentReadsMessage('sa', $sender, null);
        DB::table('promotions.segments')->where('id', $gone)->delete();

        $list = collect((array) segmentReadsStaff($sender)->get('/admin/messages?store=sa', SEGMENT_READS_JSON)->assertOk()->json('messages'))->keyBy('id');

        expect($list[$kept])->toMatchArray(['segmentId' => $held, 'segmentNameAr' => 'شريحة Kept', 'segmentNameEn' => 'Kept', 'segmentMemberCount' => 1])
            ->and($list[$deleted])->toMatchArray(['segmentId' => $gone, 'segmentNameAr' => null, 'segmentNameEn' => null, 'segmentMemberCount' => null])
            ->and($list[$store])->toMatchArray(['segmentId' => null, 'segmentNameAr' => null, 'segmentNameEn' => null, 'segmentMemberCount' => null]);
    });

    it('reads each segment once however many rows name it, and one read per segment otherwise', function () {
        config(['session.lottery' => [0, 100]]);
        $staff = Fx::staffWith(['ops.notice.manage', 'ops.message.send_many'], ['sa'], RoleLevel::Admin);
        $browser = segmentReadsStaff($staff);
        $count = static function (string $address) use ($browser): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $browser->get($address, SEGMENT_READS_JSON)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        segmentReadsNotice('sa', $staff, null);
        segmentReadsMessage('sa', $staff, null);
        // Warm the caches (the stores, the permissions), as a second request finds them.
        $count('/admin/notices?store=sa');
        $count('/admin/messages?store=sa');
        $none = [$count('/admin/notices?store=sa'), $count('/admin/messages?store=sa')];

        $shared = segmentReadsSegment('sa', 'Shared');

        for ($i = 0; $i < 5; $i++) {
            segmentReadsNotice('sa', $staff, $shared);
            segmentReadsMessage('sa', $staff, $shared);
        }

        $oneSegment = [$count('/admin/notices?store=sa'), $count('/admin/messages?store=sa')];

        for ($i = 0; $i < 19; $i++) {
            $segment = segmentReadsSegment('sa', 'S'.$i);
            segmentReadsNotice('sa', $staff, $segment);
            segmentReadsMessage('sa', $staff, $segment);
        }

        $full = [$count('/admin/notices?store=sa'), $count('/admin/messages?store=sa')];

        // Measured 2026-11-01, with the caches warm, the session and the staff member's sign-in included. One
        // read of a segment each: PromotionsApi has none of several (ops.md §4.4), so a full page of 20
        // different segments costs 20 more. Targets, not caps (owner, 2026-10-19).
        expect($none)->toBe([11, 11])
            ->and($oneSegment)->toBe([12, 12])
            ->and($full)->toBe([31, 31]);
    });

    it('lists a form\'s segments in the same number of queries however many there are', function () {
        config(['session.lottery' => [0, 100]]);
        $browser = segmentReadsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
        $count = static function () use ($browser): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $browser->get('/admin/notices/segments?store=sa', SEGMENT_READS_JSON)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        segmentReadsSegment('sa', 'First');
        $count();
        $few = $count();

        for ($i = 0; $i < 5; $i++) {
            segmentReadsSegment('sa', 'S'.$i);
        }

        // Measured 2026-11-01, as above. Targets, not caps.
        expect($count())->toBe($few)
            ->and($few)->toBe(10);
    });
});
