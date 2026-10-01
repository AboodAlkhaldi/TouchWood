<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\FlaggedField;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestAnswer;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| What a rejection says to fix and to add, and the answers the next draft gives, as stored (b2b.md
| §5, amendment 4): the three tables, what the database refuses, and what the code refuses first.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function requestTablesId(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * A rejection's requests: text first, then a file, then another text — each with its own id.
 *
 * @return array{text: string, file: string, spare: string, requests: list<ApplicationRequest>}
 */
function requestTablesRequests(): array
{
    $text = requestTablesId();
    $file = requestTablesId();
    $spare = requestTablesId();

    return [
        'text' => $text,
        'file' => $file,
        'spare' => $spare,
        'requests' => [
            ApplicationRequest::add($text, RequestKind::Text, 'Your trade name as registered', 2),
            ApplicationRequest::add($file, RequestKind::File, 'A bank letter confirming the account', 1),
            ApplicationRequest::add($spare, RequestKind::Text, 'Anything else we should know', 3),
        ],
    ];
}

/**
 * @return list<string> the table's row ids for one application, in id order
 */
function requestTablesRowIds(string $table, string $applicationId): array
{
    return array_values(DB::table("b2b.{$table}")->where('application_id', $applicationId)->orderBy('id')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
}

function requestTablesAnswerRowId(string $applicationId, string $requestId): ?string
{
    $id = DB::table('b2b.application_request_answers')->where('application_id', $applicationId)->where('request_id', $requestId)->value('id');

    return $id === null ? null : (string) $id;
}

function requestTablesFind(string $applicationId): Application
{
    return app(ApplicationRepository::class)->find($applicationId) ?? throw new LogicException("No application {$applicationId}.");
}

/**
 * @param  array<string, mixed>  $values
 */
function requestTablesFlagRow(string $applicationId, array $values): void
{
    DB::table('b2b.application_flags')->insert(['id' => requestTablesId(), 'application_id' => $applicationId, ...$values]);
}

/**
 * @param  array<string, mixed>  $values
 */
function requestTablesRequestRow(string $applicationId, array $values): void
{
    DB::table('b2b.application_requests')->insert([
        'id' => requestTablesId(), 'application_id' => $applicationId, 'kind' => 'TEXT', 'label' => 'A label', 'position' => 1, ...$values,
    ]);
}

/**
 * @param  array<string, mixed>  $values
 */
function requestTablesAnswerRow(string $applicationId, string $requestId, array $values): void
{
    DB::table('b2b.application_request_answers')->insert([
        'id' => requestTablesId(), 'application_id' => $applicationId, 'request_id' => $requestId, 'text' => null, 'media_id' => null, ...$values,
    ]);
}

/**
 * A rejection with a flag on the name and on the first document, and three requests; and the next
 * draft, stored, with the text request answered and the file request answered by a file.
 *
 * @return array{rejected: string, draft: string, company: string, text: string, file: string, spare: string, documentType: string, answerFile: string}
 */
function requestTablesScene(): array
{
    $customerId = B2BFixtures::companyAccount();
    $documentType = B2BFixtures::documentTypes()[0]->id();
    $requests = requestTablesRequests();
    [$company, $rejected] = B2BFixtures::rejected($customerId, [ApplicationFlag::field(FlaggedField::Name), ApplicationFlag::document($documentType)], $requests['requests']);

    $draft = B2BFixtures::storedDraft($customerId, $company->id());
    $last = app(ApplicationRepository::class)->lastSent($company->id());
    $answerFile = B2BFixtures::privateFile();
    $draft->answer($last, $requests['text'], RequestAnswer::text($requests['text'], Remark::of('answer', 'Al Noor Trading Establishment')));
    $draft->answer($last, $requests['file'], RequestAnswer::file($requests['file'], $answerFile));
    app(ApplicationRepository::class)->update($draft);

    return [
        'rejected' => $rejected->id(),
        'draft' => $draft->id(),
        'company' => $company->id(),
        'text' => $requests['text'],
        'file' => $requests['file'],
        'spare' => $requests['spare'],
        'documentType' => $documentType,
        'answerFile' => $answerFile,
    ];
}

describe('the repository', function () {
    it('stores a rejection\'s flags and requests and reads them back as they were, the requests in position order', function () {
        $documentType = B2BFixtures::documentTypes()[0]->id();
        $requests = requestTablesRequests();
        [, $rejected] = B2BFixtures::rejected(B2BFixtures::companyAccount(), [
            ApplicationFlag::field(FlaggedField::Name),
            ApplicationFlag::field(FlaggedField::Address),
            ApplicationFlag::document($documentType),
        ], $requests['requests']);

        $read = requestTablesFind($rejected->id());

        expect(array_map(static fn (ApplicationFlag $flag): string => $flag->key(), $read->flags()))
            ->toEqualCanonicalizing(['field:name', 'field:address', 'document:'.$documentType])
            ->and(array_map(static fn (ApplicationRequest $request): array => [$request->id, $request->kind, $request->label, $request->position], $read->requests()))
            ->toBe([
                [$requests['file'], RequestKind::File, 'A bank letter confirming the account', 1],
                [$requests['text'], RequestKind::Text, 'Your trade name as registered', 2],
                [$requests['spare'], RequestKind::Text, 'Anything else we should know', 3],
            ])
            ->and(requestTablesRowIds('application_flags', $rejected->id()))->toHaveCount(3);
    });

    it('keeps every flag and request row when the rejection is stored again, an answer pointing at one included', function () {
        $scene = requestTablesScene();
        $flags = requestTablesRowIds('application_flags', $scene['rejected']);
        $requests = requestTablesRowIds('application_requests', $scene['rejected']);

        // The draft's answer holds a request by a RESTRICT key: a rewrite would be refused.
        app(ApplicationRepository::class)->update(requestTablesFind($scene['rejected']));

        expect($flags)->toHaveCount(2)
            ->and($requests)->toHaveCount(3)
            ->and(requestTablesRowIds('application_flags', $scene['rejected']))->toBe($flags)
            ->and(requestTablesRowIds('application_requests', $scene['rejected']))->toBe($requests);
    });

    it('stores a draft\'s answers: an unchanged one keeps its row, a replaced one is written again, a removed one is gone', function () {
        $scene = requestTablesScene();
        $repository = app(ApplicationRepository::class);
        $last = $repository->lastSent($scene['company']);
        $read = requestTablesFind($scene['draft']);

        expect($read->answers()[$scene['text']]->text?->value)->toBe('Al Noor Trading Establishment')
            ->and($read->answers()[$scene['file']]->mediaId)->toBe($scene['answerFile']);

        $textRow = requestTablesAnswerRowId($scene['draft'], $scene['text']);
        $fileRow = requestTablesAnswerRowId($scene['draft'], $scene['file']);
        $newer = B2BFixtures::privateFile();
        $read->answer($last, $scene['file'], RequestAnswer::file($scene['file'], $newer));
        $repository->update($read);

        $rewritten = requestTablesAnswerRowId($scene['draft'], $scene['file']);

        expect(requestTablesAnswerRowId($scene['draft'], $scene['text']))->toBe($textRow)
            ->and($rewritten)->not->toBeNull()
            ->and($rewritten === $fileRow)->toBeFalse()
            ->and(requestTablesFind($scene['draft'])->answers()[$scene['file']]->mediaId)->toBe($newer);

        $again = requestTablesFind($scene['draft']);
        $again->removeAnswer($scene['text']);
        $repository->update($again);

        expect(requestTablesAnswerRowId($scene['draft'], $scene['text']))->toBeNull()
            ->and(array_keys(requestTablesFind($scene['draft'])->answers()))->toBe([$scene['file']]);
    });

    it('deletes a draft\'s answers with it, and leaves the rejection\'s requests and the file', function () {
        $scene = requestTablesScene();

        app(ApplicationRepository::class)->delete($scene['draft']);

        expect(requestTablesRowIds('application_request_answers', $scene['draft']))->toBe([])
            ->and(requestTablesRowIds('application_requests', $scene['rejected']))->toHaveCount(3)
            ->and(DB::table('platform.media')->where('id', $scene['answerFile'])->exists())->toBeTrue();
    });
});

describe('the last application sent', function () {
    it('is the one sent, never a newer draft', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company, $rejected] = B2BFixtures::rejected($customerId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $draft = B2BFixtures::storedDraft($customerId, $company->id());

        expect(app(ApplicationRepository::class)->openFor($customerId)?->id())->toBe($draft->id())
            ->and(app(ApplicationRepository::class)->lastSent($company->id())?->id())->toBe($rejected->id());
    });

    it('is the newer of two sent', function () {
        $customerId = B2BFixtures::companyAccount();
        [$company] = B2BFixtures::rejected($customerId);
        $repository = app(ApplicationRepository::class);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $second = B2BFixtures::storedDraft($customerId, $company->id());
        $second->submit($company->id(), B2BFixtures::companyTypes(), B2BFixtures::documentTypes(), $repository->lastSent($company->id()), CarbonImmutable::now(), B2BFixtures::reference());
        $repository->update($second);

        expect($repository->lastSent($company->id())?->id())->toBe($second->id());
    });

    it('is none for a company that sent none, and none for an id that is not one, without asking the database', function () {
        expect(app(ApplicationRepository::class)->lastSent(requestTablesId()))->toBeNull();

        DB::enableQueryLog();

        expect(app(ApplicationRepository::class)->lastSent('nope'))->toBeNull()
            ->and(DB::getQueryLog())->toBe([]);
    });
});

describe('what the database refuses on its own', function () {
    it('refuses a row the code would never write', function (Closure $change, string $refusal) {
        $scene = requestTablesScene();

        expect(fn () => $change($scene))->toThrow(QueryException::class, $refusal);
    })->with([
        'a flag on a field and a document both' => [fn (array $s) => requestTablesFlagRow($s['rejected'], ['field' => 'tax_number', 'document_type_id' => B2BFixtures::documentTypes()[1]->id()]), 'application_flags_one_item'],
        'a flag on nothing' => [fn (array $s) => requestTablesFlagRow($s['rejected'], []), 'application_flags_one_item'],
        'a flag on a field there is none of' => [fn (array $s) => requestTablesFlagRow($s['rejected'], ['field' => 'phone']), 'constraint "application_flags_field"'],
        'a second flag on one field' => [fn (array $s) => requestTablesFlagRow($s['rejected'], ['field' => 'name']), 'application_flags_one_per_field'],
        'a second flag on one document type' => [fn (array $s) => requestTablesFlagRow($s['rejected'], ['document_type_id' => $s['documentType']]), 'application_flags_one_per_document'],
        'a flag on a document type that does not exist' => [fn (array $s) => requestTablesFlagRow($s['rejected'], ['document_type_id' => requestTablesId()]), 'application_flags_document_type_id_foreign'],
        'a request for a photo' => [fn (array $s) => requestTablesRequestRow($s['rejected'], ['kind' => 'PHOTO']), 'application_requests_kind'],
        'a label on two lines' => [fn (array $s) => requestTablesRequestRow($s['rejected'], ['label' => "A bank letter\nsigned"]), 'application_requests_label_text'],
        'a blank label' => [fn (array $s) => requestTablesRequestRow($s['rejected'], ['label' => '   ']), 'application_requests_label_text'],
        'a label of 201 characters' => [fn (array $s) => requestTablesRequestRow($s['rejected'], ['label' => str_repeat('a', 201)]), 'character varying(200)'],
        'a position past the form' => [fn (array $s) => requestTablesRequestRow($s['rejected'], ['position' => 10001]), 'application_requests_position_range'],
        'an answer with neither text nor a file' => [fn (array $s) => requestTablesAnswerRow($s['draft'], $s['spare'], []), 'application_request_answers_one_value'],
        'an answer with text and a file' => [fn (array $s) => requestTablesAnswerRow($s['draft'], $s['spare'], ['text' => 'Both', 'media_id' => B2BFixtures::privateFile()]), 'application_request_answers_one_value'],
        'answer text with a tab' => [fn (array $s) => requestTablesAnswerRow($s['draft'], $s['spare'], ['text' => "Al Noor\tTrading"]), 'application_request_answers_text_text'],
        'answer text of 1001 characters' => [fn (array $s) => requestTablesAnswerRow($s['draft'], $s['spare'], ['text' => str_repeat('a', 1001)]), 'character varying(1000)'],
        'two answers to one request' => [fn (array $s) => requestTablesAnswerRow($s['draft'], $s['text'], ['text' => 'Again']), 'application_request_answers_one_per_request'],
        'deleting a file an answer holds' => [fn (array $s) => DB::table('platform.media')->where('id', $s['answerFile'])->delete(), 'application_request_answers_media_id_foreign'],
        'deleting a request an answer points at' => [fn (array $s) => DB::table('b2b.application_requests')->where('id', $s['text'])->delete(), 'application_request_answers_request_id_foreign'],
    ]);

    it('takes everything the code takes: a label of 200 Arabic characters, an answer of 1000 on several lines, positions 0 and 10,000', function () {
        $customerId = B2BFixtures::companyAccount();
        $label = requestTablesId();
        $last = requestTablesId();
        [$company, $rejected] = B2BFixtures::rejected($customerId, [], [
            ApplicationRequest::add($label, RequestKind::Text, str_repeat('خ', 200), 0),
            ApplicationRequest::add($last, RequestKind::Text, 'The last one', 10000),
        ]);
        $answer = str_repeat('a', 499)."\n".str_repeat('ب', 500);
        $draft = B2BFixtures::storedDraft($customerId, $company->id());
        $draft->answer(app(ApplicationRepository::class)->lastSent($company->id()), $label, RequestAnswer::text($label, Remark::of('answer', $answer)));
        app(ApplicationRepository::class)->update($draft);

        $requests = requestTablesFind($rejected->id())->requests();

        expect(mb_strlen($answer))->toBe(1000)
            ->and(requestTablesFind($draft->id())->answers()[$label]->text?->value)->toBe($answer)
            ->and(array_map(static fn (ApplicationRequest $request): int => $request->position, $requests))->toBe([0, 10000])
            ->and($requests[0]->label)->toBe(str_repeat('خ', 200));
    });
});

describe('the code refuses first: the constraint is dropped, so only the code can hold the rule', function () {
    it('stores a flag given twice once, without the unique index\'s help', function () {
        DB::statement('DROP INDEX b2b.application_flags_one_per_field');

        [, $rejected] = B2BFixtures::rejected(B2BFixtures::companyAccount(), [ApplicationFlag::field(FlaggedField::Name), ApplicationFlag::field(FlaggedField::Name)]);

        expect(DB::table('b2b.application_flags')->where('application_id', $rejected->id())->where('field', 'name')->count())->toBe(1);
    });

    it('stores one answer to a request answered twice, without the unique constraint\'s help', function () {
        DB::statement('ALTER TABLE b2b.application_request_answers DROP CONSTRAINT application_request_answers_one_per_request');
        $scene = requestTablesScene();
        $last = app(ApplicationRepository::class)->lastSent($scene['company']);
        $draft = requestTablesFind($scene['draft']);

        $draft->answer($last, $scene['text'], RequestAnswer::text($scene['text'], Remark::of('answer', 'Al Noor Trading Company')));
        app(ApplicationRepository::class)->update($draft);

        expect(DB::table('b2b.application_request_answers')->where('application_id', $scene['draft'])->where('request_id', $scene['text'])->pluck('text')->all())
            ->toBe(['Al Noor Trading Company']);
    });
});
