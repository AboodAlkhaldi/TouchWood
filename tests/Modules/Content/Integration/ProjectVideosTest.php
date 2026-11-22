<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProject;
use Modules\Content\Application\Command\UnpublishProject\UnpublishProjectHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Application\Query\Shop\ShopDocuments;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\DocumentKind;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S4.4, content.md §1.13 (amendment 4): a project's YouTube videos - at most 10, in order, each once,
| each kept as the video's id with a title per language (a pair: Arabic first, English to publish) - saved with the
| project under its own permission, audited by value. The code refuses before the database: each constraint is dropped
| inside its test and the save still refuses (lessons 15, 35, 36).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @param  array<string, mixed>  $with */
function projectVideosInput(array $with = []): DocumentInput
{
    return new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'مشروع', 'titleEn' => 'Project', 'bodyAr' => 'نص', 'bodyEn' => 'Text', 'cityAr' => 'الرياض', 'cityEn' => 'Riyadh', ...$with]);
}

/** @param  array<string, mixed>  $with */
function projectVideosSave(array $with = []): string
{
    return app(SaveProjectHandler::class)->handle(new SaveProject(projectVideosInput($with)));
}

/** @return list<array{string, string|null, string|null}> */
function projectVideosKept(string $project): array
{
    return array_values(array_map(static fn (stdClass $row): array => [(string) $row->video_id, $row->title_ar === null ? null : (string) $row->title_ar, $row->title_en === null ? null : (string) $row->title_en], DB::table('content.project_videos')->where('project_id', $project)->orderBy('position')->get()->all()));
}

function projectVideosRefusal(callable $work): string
{
    try {
        $work();
    } catch (InvalidContentInput $refused) {
        return "{$refused->attribute}:{$refused->rule}";
    } catch (MissingEnglish $missing) {
        return "{$missing->attribute}:english";
    }

    throw new LogicException('Not refused.');
}

/**
 * @param  array<string, mixed>  $row
 */
function projectVideosBroken(array $row): string
{
    try {
        DB::transaction(fn () => DB::table('content.project_videos')->insert($row));
    } catch (QueryException $refused) {
        preg_match('/(?:constraint|index) "([a-z_]+)"/', $refused->getMessage(), $found);

        return $found[1] ?? $refused->getMessage();
    }

    return 'accepted';
}

/** A project row of the store, nothing more: the parent the table's own cases need. */
function projectVideosParent(): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('content.projects')->insert(['id' => $id, 'store_id' => Fx::storeId('sa'), 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

    return $id;
}

describe('a project\'s videos', function () {
    it('keeps only each video\'s id, in the order given, with its titles, and reads them back for the form', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave(['videos' => [
            ['link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=12s', 'title_ar' => ' جولة في المطبخ ', 'title_en' => 'A tour of the kitchen'],
            ['link' => 'youtu.be/a-B_c1D2e3F'],
        ]]);
        $form = app(DocumentsHandler::class)->document(DocumentKind::Project, $project);

        expect(projectVideosKept($project))->toBe([['dQw4w9WgXcQ', 'جولة في المطبخ', 'A tour of the kitchen'], ['a-B_c1D2e3F', null, null]])
            ->and($form['videos'])->toBe([
                ['videoId' => 'dQw4w9WgXcQ', 'titleAr' => 'جولة في المطبخ', 'titleEn' => 'A tour of the kitchen'],
                ['videoId' => 'a-B_c1D2e3F', 'titleAr' => null, 'titleEn' => null],
            ]);
    });

    it('rewrites them whole on every save, in their new order, and audits them by value', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'أ', 'title_en' => 'A'], ['link' => 'https://youtu.be/a-B_c1D2e3F']]]);
        projectVideosSave(['id' => $project, 'version' => 1, 'videos' => [['link' => 'https://youtu.be/a-B_c1D2e3F', 'title_ar' => 'ب'], ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'أ', 'title_en' => 'A']]]);

        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.project.changed')->where('subject_id', $project)->value('changes'), true);

        expect(projectVideosKept($project))->toBe([['a-B_c1D2e3F', 'ب', null], ['dQw4w9WgXcQ', 'أ', 'A']])
            ->and($changes['videos'])->toBe(['dQw4w9WgXcQ,a-B_c1D2e3F', 'a-B_c1D2e3F,dQw4w9WgXcQ'])
            ->and($changes['video_titles_ar'])->toBe(['أ | ', 'ب | أ'])
            ->and($changes['video_titles_en'])->toBe(['A | ', ' | A']);

        // None sent: none kept.
        projectVideosSave(['id' => $project, 'version' => 2]);

        expect(projectVideosKept($project))->toBe([]);
    });

    it('records nothing about videos on a page or a project that has none', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE, ContentPermissions::PAGE_MANAGE]);
        $project = projectVideosSave();
        // A page's form has no videos: what it sends of them is not read at all, a link that is not YouTube's not refused.
        $page = app(SavePageHandler::class)->handle(new SavePage(projectVideosInput(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ'], ['link' => 'https://vimeo.com/1']]])));
        $created = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.project.created')->where('subject_id', $project)->value('changes'), true);

        expect($created)->not->toHaveKey('videos')
            ->and($created)->not->toHaveKey('video_titles_ar')
            // A page's form has no videos: they are a project's.
            ->and(DB::table('content.project_videos')->count())->toBe(0)
            ->and(app(DocumentsHandler::class)->document(DocumentKind::Page, $page)['videos'])->toBe([]);
    });

    it('refuses anything but a link to a YouTube video, on the videos, writing nothing', function (mixed $sent) {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ']]]);

        expect(projectVideosRefusal(fn () => projectVideosSave(['id' => $project, 'version' => 1, 'videos' => [['link' => 'https://youtu.be/a-B_c1D2e3F'], $sent]])))->toBe('videos:youtube_link')
            ->and(projectVideosKept($project))->toBe([['dQw4w9WgXcQ', null, null]])
            ->and(trans('content::errors.invalid_content_input.rules.youtube_link', [], 'en'))->toBe('Paste a YouTube link.');
    })->with([
        'another site' => [['link' => 'https://vimeo.com/123456789']],
        'a playlist' => [['link' => 'https://www.youtube.com/playlist?list=PL1234567890']],
        'nothing pasted' => [['title_ar' => 'فيديو']],
        'not a video at all' => [7],
    ]);

    it('refuses a video twice, and an eleventh', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $links = array_map(static fn (int $n): array => ['link' => 'https://youtu.be/video'.str_pad((string) $n, 6, '0', STR_PAD_LEFT)], range(1, 11));

        expect(projectVideosRefusal(fn () => projectVideosSave(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ'], ['link' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ']]])))->toBe('videos:duplicate')
            ->and(projectVideosRefusal(fn () => projectVideosSave(['videos' => $links])))->toBe('videos:too_many')
            ->and(DB::table('content.projects')->count())->toBe(0);

        expect(projectVideosKept(projectVideosSave(['videos' => array_slice($links, 0, 10)])))->toHaveCount(10);
    });

    it('holds each title as a pair: one line of at most 100 characters, Arabic before English', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $video = static fn (array $titles): array => ['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ', ...$titles]]];

        expect(projectVideosRefusal(fn () => projectVideosSave($video(['title_ar' => str_repeat('ع', 101)]))))->toBe('video_title_ar:too_long')
            ->and(projectVideosRefusal(fn () => projectVideosSave($video(['title_ar' => 'ع', 'title_en' => str_repeat('a', 101)]))))->toBe('video_title_en:too_long')
            ->and(projectVideosRefusal(fn () => projectVideosSave($video(['title_ar' => "سطر\nثان"]))))->toBe('video_title_ar:one_line')
            ->and(projectVideosRefusal(fn () => projectVideosSave($video(['title_en' => 'English only']))))->toBe('video_title_ar:required');

        expect(projectVideosKept(projectVideosSave($video(['title_ar' => 'الجزء ٢', 'title_en' => '  ']))))->toBe([['dQw4w9WgXcQ', 'الجزء 2', null]]);
    });

    it('publishes only with each video\'s English title when its Arabic one is typed, and keeps them through publish and unpublish', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $photo = Cx::image();
        $project = projectVideosSave(['photos' => [$photo], 'videos' => [['link' => 'https://youtu.be/a-B_c1D2e3F'], ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'جولة']]]);

        expect(projectVideosRefusal(fn () => app(PublishProjectHandler::class)->handle(new PublishProject($project, 1))))->toBe('video_title:english');

        projectVideosSave(['id' => $project, 'version' => 1, 'photos' => [$photo], 'videos' => [['link' => 'https://youtu.be/a-B_c1D2e3F'], ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'جولة', 'title_en' => 'A tour']]]);
        app(PublishProjectHandler::class)->handle(new PublishProject($project, 2));

        // Published, a save that blanks the English is refused, as every pair's.
        expect(projectVideosRefusal(fn () => projectVideosSave(['id' => $project, 'version' => 3, 'photos' => [$photo], 'videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'جولة']]])))->toBe('video_title:english');

        app(UnpublishProjectHandler::class)->handle(new UnpublishProject($project, 3));

        expect(projectVideosKept($project))->toBe([['a-B_c1D2e3F', null, null], ['dQw4w9WgXcQ', 'جولة', 'A tour']]);
    });

    it('is changed only by whoever manages the store\'s projects', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave();
        Cx::editor([ContentPermissions::PAGE_MANAGE]);

        expect(fn () => projectVideosSave(['id' => $project, 'version' => 1, 'videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ']]]))->toThrow(Unauthorized::class)
            ->and(projectVideosKept($project))->toBe([]);
    });

    it('goes with a project deleted: its rows are the project\'s', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ']]]);
        DB::table('content.projects')->where('id', $project)->delete();

        expect(DB::table('content.project_videos')->count())->toBe(0);
    });
});

describe('in the shop', function () {
    it('reads a published project\'s videos with its photos, each with its title in the page\'s language, in one read', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        $project = projectVideosSave(['photos' => [Cx::image()], 'videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'جولة', 'title_en' => 'A tour'], ['link' => 'https://youtu.be/a-B_c1D2e3F']]]);
        app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));
        $slugs = DB::table('content.project_translations')->where('project_id', $project)->pluck('slug', 'locale');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $en = app(ShopDocuments::class)->published(DocumentKind::Project, Fx::storeId('sa'), 'en', (string) $slugs['en']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $ar = app(ShopDocuments::class)->published(DocumentKind::Project, Fx::storeId('sa'), 'ar', (string) $slugs['ar']);

        expect($en['videos'] ?? null)->toBe([['id' => 'dQw4w9WgXcQ', 'title' => 'A tour'], ['id' => 'a-B_c1D2e3F', 'title' => null]])
            ->and($ar['videos'] ?? null)->toBe([['id' => 'dQw4w9WgXcQ', 'title' => 'جولة'], ['id' => 'a-B_c1D2e3F', 'title' => null]])
            ->and($queries)->toBe(2);
    });
});

describe('the table\'s last line', function () {
    it('takes the base row the cases below break, its titles left NULL', function () {
        expect(projectVideosBroken(['project_id' => projectVideosParent(), 'position' => 0, 'video_id' => 'dQw4w9WgXcQ']))->toBe('accepted');
    });

    it('refuses each row its constraint is for', function (array $with, string $constraint) {
        $project = projectVideosParent();
        DB::table('content.project_videos')->insert(['project_id' => $project, 'position' => 0, 'video_id' => 'a-B_c1D2e3F']);

        expect(projectVideosBroken([...['project_id' => $project, 'position' => 1, 'video_id' => 'dQw4w9WgXcQ'], ...$with]))->toBe($constraint);
    })->with([
        'an eleventh place' => [['position' => 10], 'pv_position'],
        'a place below the first' => [['position' => -1], 'pv_position'],
        'an id of 10 characters' => [['video_id' => 'dQw4w9WgXc'], 'pv_video_id'],
        'an Arabic title of spaces, the English NULL' => [['title_ar' => '  '], 'pv_titles'],
        'an English title of spaces, the Arabic NULL' => [['title_en' => ' '], 'pv_titles'],
        'the same video twice' => [['video_id' => 'a-B_c1D2e3F'], 'pv_once'],
        'a place taken' => [['position' => 0], 'project_videos_pkey'],
    ]);

    it('refuses an id of 12 characters in the column itself', function () {
        expect(fn () => DB::transaction(fn () => DB::table('content.project_videos')->insert(['project_id' => projectVideosParent(), 'position' => 0, 'video_id' => 'dQw4w9WgXcQQ'])))->toThrow(QueryException::class);
    });

    it('refuses in the code first, with the constraint dropped behind it', function (string $constraint, array $videos, string $refusal) {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        DB::statement("ALTER TABLE content.project_videos DROP CONSTRAINT {$constraint}");

        expect(projectVideosRefusal(fn () => projectVideosSave(['videos' => $videos])))->toBe($refusal)
            ->and(DB::table('content.project_videos')->count())->toBe(0);
    })->with([
        'an eleventh video' => ['pv_position', array_map(static fn (int $n): array => ['link' => 'https://youtu.be/video'.str_pad((string) $n, 6, '0', STR_PAD_LEFT)], range(1, 11)), 'videos:too_many'],
        'an id of 10 characters' => ['pv_video_id', [['link' => 'https://youtu.be/dQw4w9WgXc']], 'videos:youtube_link'],
        'the same video twice' => ['pv_once', [['link' => 'https://youtu.be/dQw4w9WgXcQ'], ['link' => 'https://youtu.be/dQw4w9WgXcQ']], 'videos:duplicate'],
    ]);

    it('turns a blank title into none in the code, so the CHECK on it is never reached: dropped, nothing breaks', function () {
        Cx::editor([ContentPermissions::PROJECT_MANAGE]);
        DB::statement('ALTER TABLE content.project_videos DROP CONSTRAINT pv_titles');
        $project = projectVideosSave(['videos' => [['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => '   ', 'title_en' => "\t"]]]);

        expect(projectVideosKept($project))->toBe([['dQw4w9WgXcQ', null, null]]);
    });
});
