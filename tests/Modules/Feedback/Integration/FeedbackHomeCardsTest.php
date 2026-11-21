<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestion;
use Modules\Feedback\Application\Command\AskQuestion\AskQuestionHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Presentation\Home\AverageRatingCard;
use Modules\Feedback\Presentation\Home\QuestionsWaitingCard;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback's parts of the admin home (dashboard.md §2 F, J; §5): the store's approved reviews' average and count, of
| all time (D3), for review moderators; the questions waiting, for whoever answers them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->setLocale('en');
});

/** @param  class-string<HomeCard>  $card */
function feedbackHomeRead(string $card, ?string $storeCode = 'sa'): ?HomeCardData
{
    return app($card)->data($storeCode === null ? HomeScope::allStores() : HomeScope::store(StoreId::fromString(Fx::storeId($storeCode))));
}

it('averages the store\'s approved reviews out of 5 with their count, never another store\'s or a review not approved', function () {
    ['product' => $product, 'variants' => $variants] = Fb::sold(['sa', 'eg']);
    $moderator = Fx::staff(superAdmin: true);
    $moderated = ['moderated_at' => CarbonImmutable::now(), 'moderated_by' => $moderator];
    $rows = [
        ['sa', ['rating' => 5, 'status' => 'APPROVED', ...$moderated]],
        ['sa', ['rating' => 4, 'status' => 'APPROVED', ...$moderated]],
        ['sa', ['rating' => 1, 'status' => 'PENDING']],
        ['sa', ['rating' => 1, 'status' => 'REJECTED', 'reason' => 'Not about the product', ...$moderated]],
        ['eg', ['rating' => 2, 'status' => 'APPROVED', ...$moderated]],
    ];
    foreach ($rows as $index => [$store, $with]) {
        Fb::reviewRow($product, $variants[0], Fx::customer("rater{$index}@example.test"), $with, $store);
    }
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

    $metric = feedbackHomeRead(AverageRatingCard::class)?->metrics[0];

    expect($metric?->lines[0]->value?->value)->toBe(4.5)
        ->and($metric?->lines[0]->value?->unit)->toBe('rating')
        ->and($metric?->lines[0]->note)->toBe('Out of 5, from 2 reviews')
        // Of all time: no change against the period before (D3).
        ->and($metric?->lines[0]->change)->toBeNull()
        ->and($metric?->href)->toBe('/admin/reviews?store=sa')
        // Not theirs: nothing read.
        ->and(feedbackHomeRead(AverageRatingCard::class, 'eg'))->toBeNull()
        ->and(feedbackHomeRead(AverageRatingCard::class, null))->toBeNull();
});

it('shows "—" with no review yet', function () {
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

    $line = feedbackHomeRead(AverageRatingCard::class)?->metrics[0]->lines[0];

    expect($line?->value)->toBeNull()
        ->and($line?->note)->toBe('No reviews yet');
});

it('counts the questions waiting for whoever answers them, a line per store for All Stores, and says nothing with none', function () {
    ['product' => $product] = Fb::sold(['sa', 'eg']);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    expect(feedbackHomeRead(QuestionsWaitingCard::class, null))->toBeNull();

    foreach (['sa', 'sa', 'eg'] as $index => $store) {
        Fx::actAsCustomer(Fx::customer("asker{$index}@example.test", $store));
        app(AskQuestionHandler::class)->handle(new AskQuestion(Fx::storeId($store), $product, 'Does it fit a 60 cm cabinet?'));
    }
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $all = feedbackHomeRead(QuestionsWaitingCard::class, null);

    expect($all?->figures[0]->value)->toBe(3)
        ->and(array_map(static fn ($row): string => $row->label.': '.$row->detail, $all->rows ?? []))->toBe(['Saudi Arabia: 2 questions', 'Egypt: 1 question'])
        ->and($all?->href)->toBe('/admin/questions');

    // A moderator of reviews is not an answerer of questions.
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));
    expect(feedbackHomeRead(QuestionsWaitingCard::class))->toBeNull();
});
