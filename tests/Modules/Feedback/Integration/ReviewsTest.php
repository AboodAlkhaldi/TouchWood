<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\CatalogItem;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReview;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReviewHandler;
use Modules\Feedback\Application\Command\RejectReview\RejectReview;
use Modules\Feedback\Application\Command\RejectReview\RejectReviewHandler;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReview;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReviewHandler;
use Modules\Feedback\Application\Command\WriteReview\WriteReview;
use Modules\Feedback\Application\Command\WriteReview\WriteReviewHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Domain\Exception\AlreadyReviewed;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\InvalidModeration;
use Modules\Feedback\Domain\Exception\NotAVerifiedPurchase;
use Modules\Feedback\Domain\Exception\ReviewNotFound;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Infrastructure\Eloquent\FeedbackCatalogUsage;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Sales\Public\Dto\ReturnedProductsDto;
use Modules\Sales\Public\Events\ReturnCompleted;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Reviews (feedback.md §1.1, §1.2, §4, §5, §6, §7; the review scenarios of §8): written once per
| product by a customer it was delivered to, in a store that sells it; waiting until the writing
| store's staff approve, reject or unpublish it — each audited, the product's rating kept with it;
| flipped to "Returned" when any of the product comes back.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Fb::sales();
});

/**
 * @param  list<string>  $stores
 */
function moderator(array $stores = ['sa']): string
{
    $staff = Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

/**
 * @return array<string, mixed>
 */
function reviewRow(string $id): array
{
    return (array) (DB::table('feedback.reviews')->where('id', $id)->first() ?? throw new RuntimeException('No such review.'));
}

describe('writing a review', function () {
    it('takes a delivered customer\'s review, waiting, showing the variant delivered last', function () {
        ['product' => $product, 'variants' => [, $eighty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $eighty);

        $id = Fb::write($sara, $product, 4, "  Quiet hinges.\r\nWorth it ٥ stars  ", locale: 'ar');
        $row = reviewRow($id);

        expect([$row['status'], $row['mark'], $row['variant_id'], $row['rating'], $row['locale'], $row['store_id'], $row['moderated_by']])
            ->toBe(['PENDING', 'VERIFIED_PURCHASE', $eighty, 4, 'ar', Fx::storeId('sa'), null])
            ->and($row['body'])->toBe("Quiet hinges.\nWorth it 5 stars");
    });

    it('marks it "Returned" at once when any of the product already came back', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty, returned: true);

        expect(reviewRow(Fb::write($sara, $product))['mark'])->toBe('RETURNED');
    });

    it('refuses a customer it was never delivered to', function () {
        ['product' => $product] = Fb::sold();

        expect(fn () => Fb::write(Fx::customer(), $product))->toThrow(NotAVerifiedPurchase::class)
            ->and(DB::table('feedback.reviews')->count())->toBe(0);
    });

    it('takes one review per product for ever, a rejected one included, in any store', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'ae']);
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product);

        expect(fn () => Fb::write($sara, $product, storeCode: 'ae'))->toThrow(AlreadyReviewed::class);

        moderator();
        app(RejectReviewHandler::class)->handle(new RejectReview($id));

        expect(fn () => Fb::write($sara, $product))->toThrow(AlreadyReviewed::class)
            ->and(DB::table('feedback.reviews')->count())->toBe(1);
    });

    it('refuses what a review cannot be, naming the field', function (Closure $write, string $attribute) {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);

        expect(Fb::thrown(fn () => $write($sara, $product), InvalidFeedbackAttribute::class)->attribute)->toBe($attribute)
            ->and(DB::table('feedback.reviews')->count())->toBe(0);
    })->with([
        'no stars' => [fn (string $c, string $p) => Fb::write($c, $p, 0), 'rating'],
        'six stars' => [fn (string $c, string $p) => Fb::write($c, $p, 6), 'rating'],
        'no text' => [fn (string $c, string $p) => Fb::write($c, $p, body: "  \n "), 'body'],
        'too much text' => [fn (string $c, string $p) => Fb::write($c, $p, body: str_repeat('ب', 2001)), 'body'],
        'another language' => [fn (string $c, string $p) => Fb::write($c, $p, locale: 'fr'), 'locale'],
        'a store that does not sell it' => [fn (string $c, string $p) => Fb::write($c, $p, storeCode: 'eg'), 'product'],
        'a product that is not one' => [fn (string $c) => Fb::write($c, 'not-a-product'), 'product'],
        'a store that is not one' => [function (string $c, string $p) {
            Fx::actAsCustomer($c);
            app(WriteReviewHandler::class)->handle(new WriteReview('not-a-store', $p, 5, 'Good', 'en'));
        }, 'store'],
        'a variant of another product' => [function (string $c, string $p) {
            ['variants' => [$other]] = Px::ready(['90 cm']);
            Fb::delivered($c, $p, $other);
            Fb::write($c, $p);
        }, 'variant'],
    ]);

    it('takes 2,000 characters, Arabic counted as characters', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);

        expect(mb_strlen(reviewRow(Fb::write($sara, $product, body: str_repeat('ب', 2000)))['body']))->toBe(2000);
    });

    it('is for a signed-in customer only, in an open store', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $eg = Fx::storeId('eg');

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(fn () => app(WriteReviewHandler::class)->handle(new WriteReview(Fx::storeId('sa'), $product, 5, 'Good', 'en')))->toThrow(Unauthorized::class);

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsCustomer($sara);
        expect(Fb::thrown(fn () => app(WriteReviewHandler::class)->handle(new WriteReview($eg, $product, 5, 'Good', 'en')), InvalidFeedbackAttribute::class)->attribute)->toBe('store');
    });
});

describe('moderating', function () {
    it('approves a waiting review: shown, counted in the product\'s rating, audited', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        Fb::delivered($sara, $product, $sixty);
        Fb::delivered($omar, $product, $sixty);
        $first = Fb::write($sara, $product, 5);
        $second = Fb::write($omar, $product, 2);
        $staff = moderator();

        app(ApproveReviewHandler::class)->handle(new ApproveReview($first));
        app(ApproveReviewHandler::class)->handle(new ApproveReview(strtoupper($second)));
        $audit = DB::table('platform.audit_entries')->where('action', 'feedback.review.approved')->where('subject_id', $first)->first();

        expect([reviewRow($first)['status'], reviewRow($first)['moderated_by']])->toBe(['APPROVED', $staff])
            ->and(reviewRow($first)['moderated_at'])->not->toBeNull()
            ->and(app(Reviews::class)->rating($product))->toBe(['sum' => 7, 'count' => 2])
            ->and(json_decode((string) $audit?->changes, true))->toBe(['status' => ['PENDING', 'APPROVED']]);
    });

    it('rejects a waiting review with or without a reason, never counted', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        Fb::delivered($sara, $product, $sixty);
        Fb::delivered($omar, $product, $sixty);
        $withReason = Fb::write($sara, $product);
        $without = Fb::write($omar, $product);
        moderator();

        app(RejectReviewHandler::class)->handle(new RejectReview($withReason, '  Mentions another shop.  '));
        app(RejectReviewHandler::class)->handle(new RejectReview($without, '   '));

        expect([reviewRow($withReason)['status'], reviewRow($withReason)['reason']])->toBe(['REJECTED', 'Mentions another shop.'])
            ->and([reviewRow($without)['status'], reviewRow($without)['reason']])->toBe(['REJECTED', null])
            ->and(app(Reviews::class)->rating($product))->toBe(['sum' => 0, 'count' => 0]);
    });

    it('unpublishes a shown review: it leaves the rating', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product, 3);
        moderator();
        app(ApproveReviewHandler::class)->handle(new ApproveReview($id));

        app(UnpublishReviewHandler::class)->handle(new UnpublishReview($id, 'Asked by the customer'));

        expect([reviewRow($id)['status'], reviewRow($id)['reason']])->toBe(['UNPUBLISHED', 'Asked by the customer'])
            ->and(app(Reviews::class)->rating($product))->toBe(['sum' => 0, 'count' => 0])
            ->and(DB::table('platform.audit_entries')->where('action', 'feedback.review.unpublished')->exists())->toBeTrue();
    });

    it('refuses a move its state does not allow, and changes nothing', function (Closure $move) {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product, 4);
        moderator();
        app(ApproveReviewHandler::class)->handle(new ApproveReview($id));

        expect(fn () => $move($id))->toThrow(InvalidModeration::class)
            ->and(reviewRow($id)['status'])->toBe('APPROVED')
            ->and(app(Reviews::class)->rating($product))->toBe(['sum' => 4, 'count' => 1]);
    })->with([
        'approving twice' => [fn (string $id) => app(ApproveReviewHandler::class)->handle(new ApproveReview($id))],
        'rejecting a shown one' => [fn (string $id) => app(RejectReviewHandler::class)->handle(new RejectReview($id))],
    ]);

    it('refuses unpublishing one never shown, and approving a rejected one', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product);
        moderator();

        expect(fn () => app(UnpublishReviewHandler::class)->handle(new UnpublishReview($id)))->toThrow(InvalidModeration::class);

        app(RejectReviewHandler::class)->handle(new RejectReview($id));
        expect(fn () => app(ApproveReviewHandler::class)->handle(new ApproveReview($id)))->toThrow(InvalidModeration::class);
    });

    it('refuses a reason longer than 500 characters', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product);
        moderator();

        expect(Fb::thrown(fn () => app(RejectReviewHandler::class)->handle(new RejectReview($id, str_repeat('x', 501))), InvalidFeedbackAttribute::class)->attribute)->toBe('reason')
            ->and(reviewRow($id)['status'])->toBe('PENDING');
    });
});

describe('who moderates', function () {
    it('is the staff of the store it was written in: another store\'s review is not found, as an unknown one', function (Closure $move) {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['ae']);
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $uae = Fb::write($sara, $product, storeCode: 'ae');
        moderator(['sa']);

        expect(fn () => $move($uae))->toThrow(ReviewNotFound::class)
            ->and(fn () => $move(strtolower((string) Str::ulid())))->toThrow(ReviewNotFound::class)
            ->and(fn () => $move('not-an-id'))->toThrow(ReviewNotFound::class);

        Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::QUESTION_ANSWER], ['*']));
        expect(fn () => $move($uae))->toThrow(Unauthorized::class);
    })->with([
        'approving' => [fn (string $id) => app(ApproveReviewHandler::class)->handle(new ApproveReview($id))],
        'rejecting' => [fn (string $id) => app(RejectReviewHandler::class)->handle(new RejectReview($id))],
        'unpublishing' => [fn (string $id) => app(UnpublishReviewHandler::class)->handle(new UnpublishReview($id))],
    ]);

    it('keeps a switched-off store\'s queue for Super Admins alone', function () {
        ['product' => $product, 'variants' => [$sixty]] = Fb::sold(['eg']);
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $egypt = Fb::write($sara, $product, storeCode: 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        moderator(['*']);

        expect(fn () => app(ApproveReviewHandler::class)->handle(new ApproveReview($egypt)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(ApproveReviewHandler::class)->handle(new ApproveReview($egypt));
        expect(reviewRow($egypt)['status'])->toBe('APPROVED');
    });
});

describe('a return', function () {
    it('flips the customer\'s reviews of the products it took back, and only those', function () {
        ['product' => $hinge, 'variants' => [$sixty]] = Fb::sold();
        ['product' => $drawer, 'variants' => [$drawerSize]] = Fb::sold();
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        Fb::delivered($sara, $hinge, $sixty);
        Fb::delivered($sara, $drawer, $drawerSize);
        Fb::delivered($omar, $hinge, $sixty);
        $sarasHinge = Fb::write($sara, $hinge);
        $sarasDrawer = Fb::write($sara, $drawer);
        $omarsHinge = Fb::write($omar, $hinge);
        moderator();
        app(ApproveReviewHandler::class)->handle(new ApproveReview($sarasHinge));
        $returnId = strtolower((string) Str::ulid());
        Fb::sales()->complete($returnId, new ReturnedProductsDto($sara, Fx::storeId('sa'), [strtoupper($hinge)]));

        event(new ReturnCompleted((string) Str::uuid(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));
        event(new ReturnCompleted((string) Str::uuid(), $returnId, strtolower((string) Str::ulid()), Fx::storeId('sa'), $sara, CarbonImmutable::now()));

        expect([reviewRow($sarasHinge)['mark'], reviewRow($sarasHinge)['status']])->toBe(['RETURNED', 'APPROVED'])
            ->and(reviewRow($sarasDrawer)['mark'])->toBe('VERIFIED_PURCHASE')
            ->and(reviewRow($omarsHinge)['mark'])->toBe('VERIFIED_PURCHASE');
    });

    it('does nothing for a return Sales does not know', function () {
        event(new ReturnCompleted((string) Str::uuid(), strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), Fx::storeId('sa'), Fx::customer(), CarbonImmutable::now()));

        expect(DB::table('feedback.reviews')->count())->toBe(0);
    });
});

describe('what Catalog may not delete', function () {
    it('answers every review naming a product or a variant, in one query', function () {
        ['product' => $product, 'variants' => [$sixty, $eighty]] = Fb::sold();
        $sara = Fx::customer();
        Fb::delivered($sara, $product, $sixty);
        $id = Fb::write($sara, $product);
        $usage = app(FeedbackCatalogUsage::class);

        expect(array_map(static fn ($use): array => [$use->module, $use->kind, $use->id, $use->itemId], $usage->usesOf(CatalogItem::Product, [$product])))->toBe([['feedback', 'review', $id, $product]])
            ->and(array_map(static fn ($use): string => $use->id, $usage->usesOf(CatalogItem::Variant, [$sixty, $eighty])))->toBe([$id])
            ->and($usage->usesOf(CatalogItem::Category, [$product]))->toBe([])
            ->and($usage->usesOf(CatalogItem::Product, []))->toBe([]);
    });
});
