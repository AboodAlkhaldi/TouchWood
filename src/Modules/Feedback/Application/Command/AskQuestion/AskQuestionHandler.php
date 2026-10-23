<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Command\AskQuestion;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\RemovedMeanwhile;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\TooManyWaitingQuestions;
use Modules\Feedback\Domain\Model\FeedbackText;
use Modules\Feedback\Domain\Repository\Questions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A customer asks about a product (feedback.md §1.3): in an open store that sells it (answer 19),
 * the text read as a review's, waiting for that store's staff. **At most five waiting in all**
 * (answer 16) — counted under a lock on the customer, so two asked at once cannot both pass it
 * (§1.4; answer 20: an advisory lock, Feedback having no customer row).
 */
final readonly class AskQuestionHandler
{
    public const string PERMISSION = FeedbackPermissions::QUESTION_ASK;

    /** Waiting at once, in every store (answer 16). */
    public const int WAITING = 5;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private Questions $questions,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute|TooManyWaitingQuestions
     */
    public function handle(AskQuestion $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);

        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidFeedbackAttribute('store', 'not a store id');
        }

        if (! ($this->platform->store($store)->isActive ?? false)) {
            throw new InvalidFeedbackAttribute('store', 'no such store open');
        }

        $productId = strtolower(trim($command->productId));
        $body = FeedbackText::of($command->body, 'body', 1000);
        // The language it is written in, whatever the shop's (§4.2 B3).
        $locale = FeedbackText::languageOf($body);

        if (! Ulids::valid($productId) || ! in_array($productId, $this->catalog->productsSoldIn($store, [$productId]), true)) {
            throw new InvalidFeedbackAttribute('product', 'not sold in this store');
        }

        try {
            return $this->db->transaction(function () use ($customerId, $productId, $store, $body, $locale): string {
                $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["feedback:questions:{$customerId}"], false);

                if ($this->questions->waiting($customerId) >= self::WAITING) {
                    throw new TooManyWaitingQuestions;
                }

                $questionId = strtolower((string) Str::ulid());
                $this->questions->create($questionId, $productId, $store, $customerId, $body, $locale, CarbonImmutable::now());

                return $questionId;
            });
        } catch (QueryException $failed) {
            throw RemovedMeanwhile::of($failed);
        }
    }
}
