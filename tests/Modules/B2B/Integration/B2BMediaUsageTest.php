<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\RequestAnswer;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Modules\B2B\Infrastructure\Media\ApplicationFilesUsage;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Media\InMemoryMediaUsages;
use Modules\Platform\Application\Query\ListMedia\ListMedia;
use Modules\Platform\Application\Query\ListMedia\ListMediaHandler;
use Modules\Platform\Application\Query\ListMedia\MediaRow;
use Modules\Platform\Domain\Exception\MediaInUse;
use Modules\Platform\Public\Dto\MediaUseDto;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| A company's papers are Platform media, and every application holding one — as a document or as
| the answer to a request, drafts included — blocks its delete (b2b.md §1.4).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local');
});

/**
 * Puts a file's bytes where Platform keeps a private file, so a test can see them stay or go.
 */
function b2bMediaUsageBytes(string $mediaId): string
{
    Storage::disk('local')->put("media/{$mediaId}.pdf", "%PDF-1.4\n%%EOF\n");

    return $mediaId;
}

function b2bMediaUsageActAsSuperAdmin(): void
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
}

/**
 * What B2B reports for a file: each use as Platform describes it, and whether it blocks.
 *
 * @return list<array{0: string, 1: bool}>
 */
function b2bMediaUsageUses(string $mediaId): array
{
    return array_map(static fn (MediaUseDto $use): array => [$use->describe(), $use->blocksDelete], app(ApplicationFilesUsage::class)->usesOf($mediaId));
}

/**
 * A staff member with every right — a Super Admin — deleting the file from the media library.
 */
function b2bMediaUsageDelete(string $mediaId): void
{
    b2bMediaUsageActAsSuperAdmin();
    app(DeleteMediaHandler::class)->handle(new DeleteMedia($mediaId));
}

/**
 * @return string the first file the application holds as a document
 */
function b2bMediaUsageFirstDocument(Application $application): string
{
    return array_values($application->documents())[0]->mediaId;
}

it('is registered with Platform', function () {
    $usages = array_filter(app(InMemoryMediaUsages::class)->all(), static fn (object $usage): bool => $usage instanceof ApplicationFilesUsage);

    expect($usages)->toHaveCount(1);
});

it('refuses a staff delete of a file an application holds, names the application, and keeps the file', function (Closure $holder) {
    /** @var array{0: string, 1: string} $held */
    $held = $holder();
    [$mediaId, $applicationId] = $held;
    b2bMediaUsageBytes($mediaId);
    $error = null;

    try {
        b2bMediaUsageDelete($mediaId);
    } catch (MediaInUse $caught) {
        $error = $caught;
    }

    expect(array_map(static fn (MediaUseDto $use): string => $use->describe(), $error->blockedBy ?? []))->toBe(["b2b.application {$applicationId}"])
        ->and(DB::table('platform.media')->where('id', $mediaId)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists("media/{$mediaId}.pdf"))->toBeTrue();
})->with([
    'a draft\'s document' => [function () {
        $draft = B2BFixtures::storedDraft(B2BFixtures::companyAccount());

        return [b2bMediaUsageFirstDocument($draft), $draft->id()];
    }],
    'a sent application\'s document' => [function () {
        [, $application] = B2BFixtures::sent(B2BFixtures::companyAccount());

        return [b2bMediaUsageFirstDocument($application), $application->id()];
    }],
    'a file answering a request' => [function () {
        $customerId = B2BFixtures::companyAccount();
        $requestId = strtolower((string) Str::ulid());
        [$company] = B2BFixtures::rejected($customerId, [], [ApplicationRequest::add($requestId, RequestKind::File, 'A bank letter confirming the account', 1)]);
        $draft = B2BFixtures::storedDraft($customerId, $company->id());
        $file = B2BFixtures::privateFile();
        $draft->answer(app(ApplicationRepository::class)->lastSent($company->id()), $requestId, RequestAnswer::file($requestId, $file));
        app(ApplicationRepository::class)->update($draft);

        return [$file, $draft->id()];
    }],
]);

it('reports every application that holds a file, and none for a file nobody holds — which staff may then delete', function () {
    $customerId = B2BFixtures::companyAccount();
    [$company, $rejected] = B2BFixtures::rejected($customerId);
    $carried = b2bMediaUsageFirstDocument($rejected);
    // The next draft carries the files of the last application sent (amendment 4(b)).
    $draft = B2BFixtures::storedDraft($customerId, $company->id());
    $draft->attach((string) array_key_first($rejected->documents()), $carried, CarbonImmutable::now());
    app(ApplicationRepository::class)->update($draft);
    $holders = [$rejected->id(), $draft->id()];
    sort($holders);

    $loose = b2bMediaUsageBytes(B2BFixtures::privateFile());

    expect(b2bMediaUsageUses($carried))->toBe(array_map(static fn (string $id): array => ["b2b.application {$id}", true], $holders))
        ->and(b2bMediaUsageUses($loose))->toBe([]);

    b2bMediaUsageDelete($loose);

    expect(DB::table('platform.media')->where('id', $loose)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists("media/{$loose}.pdf"))->toBeFalse();
});

it('never detaches a file from an application', function () {
    $draft = B2BFixtures::storedDraft(B2BFixtures::companyAccount());

    expect(fn () => app(ApplicationFilesUsage::class)->detach(b2bMediaUsageFirstDocument($draft)))->toThrow(LogicException::class);
});

it('shows a Super Admin in the media library where the file is used, and that it cannot be deleted', function () {
    $draft = B2BFixtures::storedDraft(B2BFixtures::companyAccount());
    $mediaId = b2bMediaUsageFirstDocument($draft);
    b2bMediaUsageActAsSuperAdmin();

    $rows = array_values(array_filter(
        app(ListMediaHandler::class)->handle(new ListMedia(perPage: 100))->media,
        static fn (MediaRow $row): bool => $row->id === $mediaId,
    ));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->usedIn)->toBe(["b2b.application {$draft->id()}"])
        ->and($rows[0]->deleteBlocked)->toBeTrue();
});
