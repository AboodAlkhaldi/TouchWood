<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReview;
use Modules\Feedback\Application\Command\ApproveReview\ApproveReviewHandler;
use Modules\Feedback\Application\Command\RejectReview\RejectReview;
use Modules\Feedback\Application\Command\RejectReview\RejectReviewHandler;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReview;
use Modules\Feedback\Application\Command\UnpublishReview\UnpublishReviewHandler;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Feedback telling Catalog's lists (feedback.md §1.2, §6; catalog.md amendment 25): each product's
| rating pushed with every approval and unpublishing, in the same transaction; a store's ratings switch
| pushed when an admin changes it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Fb::sales();
});

/**
 * @return array{0: int, 1: int}|null
 */
function catalogRating(string $productId): ?array
{
    $row = DB::table('catalog.product_ratings')->where('product_id', $productId)->first();

    return $row === null ? null : [(int) $row->rating_sum, (int) $row->rating_count];
}

it('pushes the product\'s rating with each approval and unpublishing, never with a rejection', function () {
    ['product' => $product, 'variants' => [$sixty]] = Fb::sold();
    $sara = Fx::customer();
    $omar = Fx::customer('omar@example.test');
    $lina = Fx::customer('lina@example.test');

    foreach ([$sara, $omar, $lina] as $customer) {
        Fb::delivered($customer, $product, $sixty);
    }

    $five = Fb::write($sara, $product, 5);
    $three = Fb::write($omar, $product, 3);
    $one = Fb::write($lina, $product, 1);
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

    app(ApproveReviewHandler::class)->handle(new ApproveReview($five));
    expect(catalogRating($product))->toBe([5, 1]);

    app(ApproveReviewHandler::class)->handle(new ApproveReview($three));
    app(RejectReviewHandler::class)->handle(new RejectReview($one));
    expect(catalogRating($product))->toBe([8, 2]);

    app(UnpublishReviewHandler::class)->handle(new UnpublishReview($five));
    expect(catalogRating($product))->toBe([3, 1]);
});

it('pushes a store\'s ratings switch when an admin changes it, and nothing for another setting', function () {
    $sa = Fx::storeId('sa');
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin));

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(FeedbackSettings::RATINGS_IN_LISTS, 'sa', false));
    expect(DB::table('catalog.store_rating_display')->where('store_id', $sa)->value('shown'))->toBeFalse()
        ->and(DB::table('catalog.store_rating_display')->count())->toBe(1);

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(FeedbackSettings::RATINGS_IN_LISTS, 'sa', true));
    expect(DB::table('catalog.store_rating_display')->where('store_id', $sa)->value('shown'))->toBeTrue();

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(UpdateSettingHandler::class)->handle(new UpdateSetting('loyalty.points.enabled', 'eg', true));
    expect(DB::table('catalog.store_rating_display')->count())->toBe(1);
});
