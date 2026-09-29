<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AnswerApplicationRequest;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Draft\OpenDrafts;
use Modules\B2B\Application\Files\ApplicationFiles;
use Modules\B2B\Domain\Exception\AnswerKindMismatch;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Exception\RequestNotFound;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\ApplicationState;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\B2B\Domain\ValueObject\RequestAnswer;
use Modules\B2B\Domain\ValueObject\RequestKind;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\Error\DomainError;

/**
 * Answers a request of the last rejection (b2b.md §1.2, §3.1, amendments 4 and 5). A second answer
 * replaces the first, and a file it replaces is let go of.
 *
 * - A request that is not the last rejection's → RequestNotFound — including on a first draft,
 *   which has no rejection before it.
 * - The wrong kind (text for a file, a file for text) → AnswerKindMismatch.
 *
 * Both are asked **before a file is stored**, so a refused answer leaves nothing behind. B2B
 * uploads a file answer itself, private, as it does a paper (amendment 5). Not audited (amendment 4).
 */
final readonly class AnswerApplicationRequestHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private OpenDrafts $drafts,
        private ApplicationRepository $applications,
        private ApplicationFiles $files,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|InvalidCompanyAttribute|RequestNotFound|AnswerKindMismatch
     * @throws ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     * @throws DomainError Platform's refusal of the file itself (type or size)
     */
    public function handle(AnswerApplicationRequest $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->account->get(self::PERMISSION)->id;
        $requestId = strtolower($command->requestId);
        [$text, $file] = self::given($command);

        $this->db->transaction(function () use ($customerId, $requestId, $text, $file, $command): void {
            $inHand = $this->drafts->forChange($customerId);
            $draft = $inHand->draft;
            $lastSent = $inHand->company === null ? null : $this->applications->lastSent($inHand->company->id());

            $request = self::request($lastSent, $requestId);
            $kind = $file ? RequestKind::File : RequestKind::Text;

            if ($kind !== $request->kind) {
                throw new AnswerKindMismatch($request->kind->value, $kind->value);
            }

            $answer = $text !== null
                ? RequestAnswer::text($requestId, $text)
                : RequestAnswer::file($requestId, $this->files->upload((string) $command->path, (string) $command->originalFilename));

            $replaced = $draft->answer($lastSent, $requestId, $answer);

            $this->applications->update($draft);
            $this->files->release([$replaced]);
        }, 3);
    }

    /**
     * The request, from the last application sent — which asks only if it was rejected.
     *
     * @throws RequestNotFound
     */
    private static function request(?Application $lastSent, string $requestId): ApplicationRequest
    {
        if ($lastSent === null || $lastSent->state() !== ApplicationState::Rejected) {
            throw new RequestNotFound($requestId);
        }

        foreach ($lastSent->requests() as $request) {
            if ($request->id === $requestId) {
                return $request;
            }
        }

        throw new RequestNotFound($requestId);
    }

    /**
     * Exactly one of a text and a file.
     *
     * @return array{0: Remark|null, 1: bool} the text answer, or whether a file was sent
     *
     * @throws InvalidCompanyAttribute
     */
    private static function given(AnswerApplicationRequest $command): array
    {
        $text = $command->text === null || trim($command->text) === '' ? null : $command->text;
        $file = $command->path !== null && $command->path !== '';

        if (($text === null) === ! $file) {
            throw new InvalidCompanyAttribute('answer', 'a text or a file');
        }

        return [$text === null ? null : Remark::of('answer', $text), $file];
    }
}
