<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Catalog\Public\Contracts\ShopProductSections;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Shop\ShopFeedback;
use Modules\Feedback\Application\Shop\ShopFeedbackQueries;
use Modules\Feedback\Domain\Repository\Questions;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Domain\Repository\Wishlist;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseFeedbackQueries;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseQuestions;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseReviews;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseShopFeedbackQueries;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseWishlist;
use Modules\Feedback\Infrastructure\Eloquent\FeedbackCatalogUsage;
use Modules\Feedback\Infrastructure\Listener\ClearAnonymizedWishlist;
use Modules\Feedback\Infrastructure\Listener\FlipReturnedReviews;
use Modules\Feedback\Infrastructure\Listener\TellCatalogOfRatingsSwitch;
use Modules\Feedback\Presentation\Console\RepushRatingsCommand;
use Modules\Feedback\Presentation\Storefront\QuestionsSection;
use Modules\Feedback\Presentation\Storefront\ReviewsSection;
use Modules\Feedback\Presentation\Storefront\WishlistSection;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Events\SettingChanged;
use Modules\Sales\Public\Events\ReturnCompleted;

/**
 * Feedback (feedback.md). Registered after Access, Catalog and Sales: it declares its permissions
 * through Access's public catalog, its setting through Platform, and depends on Platform, Access,
 * Catalog and Sales only (handoff §4.4).
 */
final class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Reviews::class, DatabaseReviews::class);
        $this->app->bind(Questions::class, DatabaseQuestions::class);
        $this->app->bind(Wishlist::class, DatabaseWishlist::class);
        $this->app->bind(FeedbackQueries::class, DatabaseFeedbackQueries::class);
        $this->app->bind(ShopFeedbackQueries::class, DatabaseShopFeedbackQueries::class);
        // Read once a request for the product page's three sections (catalog.md amendment 26(b)).
        $this->app->scoped(ShopFeedback::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'feedback');

        $this->app->make(PermissionCatalog::class)->declare('feedback', ...FeedbackPermissions::definitions());
        // Stars and the rating sort in a store's lists (§1.2), on Platform's settings page.
        $this->app->make(SettingsRegistry::class)->define('feedback', ...FeedbackSettings::definitions());
        // What its reviews name of Catalog's, asked before Catalog deletes it (catalog.md amendment 19).
        $this->app->make(CatalogUsages::class)->register('feedback', FeedbackCatalogUsage::class);
        // Its parts of a product's page in the shop (§2.3; catalog.md amendment 26(b)).
        $this->app->make(ShopProductSections::class)->register('feedback.reviews', ReviewsSection::class);
        $this->app->make(ShopProductSections::class)->register('feedback.questions', QuestionsSection::class);
        $this->app->make(ShopProductSections::class)->register('feedback.wishlist', WishlistSection::class);

        // A return completed: the reviews of what came back read "Returned" (§6).
        Event::listen(ReturnCompleted::class, [FlipReturnedReviews::class, 'handle']);
        // An anonymized account's wishlist is deleted (§1.5).
        Event::listen(CustomerAnonymized::class, [ClearAnonymizedWishlist::class, 'handle']);
        // The ratings switch reaches Catalog's lists (§1.2).
        Event::listen(SettingChanged::class, [TellCatalogOfRatingsSwitch::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([RepushRatingsCommand::class]);
        }
    }
}
