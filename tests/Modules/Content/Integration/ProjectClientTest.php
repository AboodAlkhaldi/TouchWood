<?php

declare(strict_types=1);

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
use Modules\Content\Domain\Exception\ImageNotReady;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\MissingEnglish;
use Modules\Content\Domain\Model\DocumentKind;
use Modules\Content\Infrastructure\Media\ContentMediaUsage;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Domain\Exception\MediaInUse;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| flags-2026-11.md S4.1, content.md §1.13 (amendment 3): who a project was made with. A client name per language (100
| characters), a logo from the library (a public image, held by a RESTRICT key, counted as used while the project is
| published) and a link that is an https:// address and nothing else - all optional.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @param  array<string, mixed>  $with */
function clientInput(array $with = []): DocumentInput
{
    return new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'مشروع', 'titleEn' => 'Project', 'bodyAr' => 'نص', 'bodyEn' => 'Text', 'cityAr' => 'الرياض', 'cityEn' => 'Riyadh', ...$with]);
}

function clientRefusal(callable $work): string
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

it('keeps a project\'s client: a name per language, a logo and an https link, and reads them back', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $logo = Cx::image();
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientNameAr' => '  شركة الأفق ', 'clientNameEn' => 'Horizon Co', 'clientLogoMediaId' => strtoupper($logo), 'clientLink' => ' https://horizon.example/work '])));
    $form = app(DocumentsHandler::class)->document(DocumentKind::Project, $project);

    expect($form['texts']['ar']['clientName'])->toBe('شركة الأفق')
        ->and($form['texts']['en']['clientName'])->toBe('Horizon Co')
        ->and($form['client_logo_media'])->toBe($logo)
        ->and($form['client_link'])->toBe('https://horizon.example/work')
        ->and(DB::table('content.projects')->where('id', $project)->value('client_logo_media'))->toBe($logo);
});

it('keeps a project with no client as it was: every field optional', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput()));
    $form = app(DocumentsHandler::class)->document(DocumentKind::Project, $project);

    expect($form['texts']['ar']['clientName'])->toBeNull()
        ->and($form['client_logo_media'])->toBeNull()
        ->and($form['client_link'])->toBeNull();

    // A box emptied later empties the field.
    $saved = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['id' => $project, 'version' => 1, 'clientNameAr' => 'عميل', 'clientNameEn' => 'Client', 'clientLink' => 'https://client.example'])));
    app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['id' => $saved, 'version' => 2, 'clientNameAr' => '  ', 'clientLink' => ''])));

    expect(DB::table('content.projects')->where('id', $project)->value('client_link'))->toBeNull()
        ->and(DB::table('content.project_translations')->where('project_id', $project)->where('locale', 'ar')->value('client_name'))->toBeNull();
});

it('takes an https link only: a shop page, http and other schemes are refused on the link', function (string $link) {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);

    expect(clientRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientLink' => $link])))))->toBe('client_link:https_link');
})->with([
    'http' => 'http://client.example',
    'a page of the shop' => '/projects/other',
    'javascript' => 'javascript:alert(1)',
    'no scheme' => 'client.example',
    'a protocol-relative address' => '//client.example',
    'a space inside' => 'https://client.example/a b',
    'no host' => 'https:///path',
]);

it('keeps the scheme of an https link in lower case, whatever a phone capitalised, so the column\'s CHECK takes it', function (string $typed, string $kept) {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientLink' => $typed])));

    expect(DB::table('content.projects')->where('id', $project)->value('client_link'))->toBe($kept);
})->with([
    'Https' => ['Https://Client.example/Work', 'https://Client.example/Work'],
    'HTTPS' => ['HTTPS://client.example', 'https://client.example'],
]);

it('refuses a client name of more than 100 characters, naming the language', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);

    expect(clientRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientNameAr' => str_repeat('ع', 101)])))))->toBe('client_name_ar:too_long')
        ->and(clientRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientNameAr' => 'عميل', 'clientNameEn' => str_repeat('a', 101)])))))->toBe('client_name_en:too_long');

    $kept = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientNameAr' => str_repeat('ع', 100)])));

    expect(mb_strlen((string) DB::table('content.project_translations')->where('project_id', $kept)->where('locale', 'ar')->value('client_name')))->toBe(100);
});

it('refuses a logo that is not a public image of the library, on the logo', function (string $sizes, string $visibility, string $mime) {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $logo = Cx::image($sizes, $visibility, $mime);

    expect(clientRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientLogoMediaId' => $logo])))))->toBe('client_logo:image_file');
})->with([
    'a private file' => ['READY', 'PRIVATE', 'image/jpeg'],
    'a PDF' => ['READY', 'PUBLIC', 'application/pdf'],
    'a GIF' => ['READY', 'PUBLIC', 'image/gif'],
]);

it('refuses a logo that is no file at all', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);

    expect(clientRefusal(fn () => app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientLogoMediaId' => strtolower((string) Str::ulid())])))))->toBe('client_logo:image_file');
});

it('publishes only with the client name in English when the Arabic is typed, and a logo whose sizes are ready', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $photo = Cx::image();
    $pending = Cx::image('PENDING');
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['photos' => [$photo], 'clientNameAr' => 'عميل'])));

    expect(clientRefusal(fn () => app(PublishProjectHandler::class)->handle(new PublishProject($project, 1))))->toBe('client_name:english');

    app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['id' => $project, 'version' => 1, 'photos' => [$photo], 'clientNameAr' => 'عميل', 'clientNameEn' => 'Client', 'clientLogoMediaId' => $pending])));

    expect(fn () => app(PublishProjectHandler::class)->handle(new PublishProject($project, 2)))->toThrow(ImageNotReady::class);

    app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['id' => $project, 'version' => 2, 'photos' => [$photo], 'clientNameAr' => 'عميل', 'clientNameEn' => 'Client'])));
    app(PublishProjectHandler::class)->handle(new PublishProject($project, 3));

    expect(DB::table('content.projects')->where('id', $project)->value('status'))->toBe('PUBLISHED');
});

it('leaves the client as it is when the project is published and unpublished, and keeps it through a save of a published one', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $logo = Cx::image();
    $input = static fn (array $with = []): DocumentInput => clientInput(['photos' => [Cx::image()], 'clientNameAr' => 'عميل', 'clientNameEn' => 'Client', 'clientLogoMediaId' => $logo, 'clientLink' => 'https://client.example', ...$with]);
    $project = app(SaveProjectHandler::class)->handle(new SaveProject($input()));
    app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));
    app(UnpublishProjectHandler::class)->handle(new UnpublishProject($project, 2));

    expect(DB::table('content.projects')->where('id', $project)->value('client_logo_media'))->toBe($logo)
        ->and(DB::table('content.projects')->where('id', $project)->value('client_link'))->toBe('https://client.example')
        ->and(DB::table('content.project_translations')->where('project_id', $project)->where('locale', 'en')->value('client_name'))->toBe('Client');
});

it('counts the logo as used: it blocks the file\'s delete while the project is published, and is detached from a draft', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $logo = Cx::image();
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['photos' => [Cx::image()], 'clientLogoMediaId' => $logo])));

    expect(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], app(ContentMediaUsage::class)->usesOf($logo)))->toBe([[$project, false]]);

    app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));

    expect(array_map(static fn ($use): array => [$use->subjectId, $use->blocksDelete], app(ContentMediaUsage::class)->usesOf($logo)))->toBe([[$project, true]]);

    $admin = Fx::staff(superAdmin: true);
    Fx::actAsStaff($admin);

    expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($logo)))->toThrow(MediaInUse::class);

    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    app(UnpublishProjectHandler::class)->handle(new UnpublishProject($project, 2));
    $admin = Fx::staff(superAdmin: true);
    Fx::actAsStaff($admin);
    app(DeleteMediaHandler::class)->handle(new DeleteMedia($logo));

    expect(DB::table('content.projects')->where('id', $project)->value('client_logo_media'))->toBeNull()
        ->and(DB::table('platform.media')->where('id', $logo)->exists())->toBeFalse()
        ->and(DB::table('content.projects')->where('id', $project)->value('version'))->toBe(4)
        ->and(Fx::audits('content.project.image_detached', $project))->toBe(1);
});

it('holds the logo by a RESTRICT key as every Content image is', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $logo = Cx::image();
    app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['clientLogoMediaId' => $logo])));

    expect(fn () => DB::transaction(fn () => DB::table('platform.media')->where('id', $logo)->delete()))->toThrow(QueryException::class);
});

it('audits the client fields by value', function () {
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(clientInput()));
    app(SaveProjectHandler::class)->handle(new SaveProject(clientInput(['id' => $project, 'version' => 1, 'clientNameEn' => null, 'clientNameAr' => 'عميل', 'clientLink' => 'https://client.example'])));

    $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'content.project.changed')->where('subject_id', $project)->value('changes'), true);

    expect($changes['client_name_ar'])->toBe([null, 'عميل'])
        ->and($changes['client_link'])->toBe([null, 'https://client.example']);
});

it('gives a page no client: the fields belong to a project', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = app(SavePageHandler::class)->handle(new SavePage(clientInput(['clientNameAr' => 'عميل', 'clientLink' => 'http://not-checked.example'])));
    $form = app(DocumentsHandler::class)->document(DocumentKind::Page, $page);

    expect($form['texts']['ar']['clientName'])->toBeNull()
        ->and($form['client_link'])->toBeNull();
});
