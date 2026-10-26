<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\CustomerPageSections;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\CustomerPageSectionDto;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Catalog\Public\Contracts\ShopCardFacts;
use Modules\Catalog\Public\Contracts\ShopProductSections;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Shop\ShopFeedback;
use Modules\Feedback\Application\Shop\ShopFeedbackQueries;
use Modules\Feedback\Domain\Repository\Questions;
use Modules\Feedback\Domain\Repository\RejectReasons;
use Modules\Feedback\Domain\Repository\Reviews;
use Modules\Feedback\Domain\Repository\Wishlist;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseFeedbackQueries;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseQuestions;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseRejectReasons;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseReviews;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseShopFeedbackQueries;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseWishlist;
use Modules\Feedback\Infrastructure\Eloquent\FeedbackCatalogUsage;
use Modules\Feedback\Infrastructure\Listener\ClearAnonymizedWishlist;
use Modules\Feedback\Infrastructure\Listener\FlipReturnedReviews;
use Modules\Feedback\Infrastructure\Listener\TellCatalogOfRatingsSwitch;
use Modules\Feedback\Presentation\Console\RepushRatingsCommand;
use Modules\Feedback\Presentation\Http\Resource\WishlistCustomerSection;
use Modules\Feedback\Presentation\Storefront\QuestionsSection;
use Modules\Feedback\Presentation\Storefront\ReviewsSection;
use Modules\Feedback\Presentation\Storefront\SavedCardFact;
use Modules\Feedback\Presentation\Storefront\WishlistSection;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\MenuEntryDto;
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
        $this->app->bind(RejectReasons::class, DatabaseRejectReasons::class);
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
        $this->app->make(ShopProductSections::class)->register('feedback.reviews', ReviewsSection::class, ['feedback::shop', 'feedback::errors']);
        $this->app->make(ShopProductSections::class)->register('feedback.questions', QuestionsSection::class);
        $this->app->make(ShopProductSections::class)->register('feedback.wishlist', WishlistSection::class, evenUnavailable: true);
        // The Save heart on every product card (§2.4 B5; catalog.md amendment 32).
        $this->app->make(ShopCardFacts::class)->register('feedback.saved', SavedCardFact::class);

        // A return completed: the reviews of what came back read "Returned" (§6).
        Event::listen(ReturnCompleted::class, [FlipReturnedReviews::class, 'handle']);
        // An anonymized account's wishlist is deleted (§1.5).
        Event::listen(CustomerAnonymized::class, [ClearAnonymizedWishlist::class, 'handle']);
        // The ratings switch reaches Catalog's lists (§1.2).
        Event::listen(SettingChanged::class, [TellCatalogOfRatingsSwitch::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([RepushRatingsCommand::class]);
        }

        /*
        | The screens (§4.1). Support: the review and question queues for the store's moderators and
        | answerers; Customers: Favourited Products for wishlist readers. What is offered is never what is
        | allowed: every handler behind these asks again (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('feedback', 'reviews', 'support', 'feedback.admin.reviews', FeedbackPermissions::REVIEW_MODERATE, 10, icon: 'reviews'),
            new MenuEntryDto('feedback', 'questions', 'support', 'feedback.admin.questions', FeedbackPermissions::QUESTION_ANSWER, 20, icon: 'questions'),
            new MenuEntryDto('feedback', 'favourites', 'customers', 'feedback.admin.favourites', FeedbackPermissions::WISHLIST_VIEW, 30, icon: 'favourites'),
            // The ready reasons, one list for every store: read by moderators, edited with the settings job everywhere (§4.2 B4).
            new MenuEntryDto('feedback', 'reasons', 'support', 'feedback.admin.reasons', [FeedbackPermissions::REVIEW_MODERATE, FeedbackPermissions::QUESTION_ANSWER, FeedbackPermissions::SETTINGS_UPDATE], 40, icon: 'reasons'),
        );

        // A customer's wishlist on the staff customer page, a block per store the reader covers (§4.1 F4).
        $this->app->make(CustomerPageSections::class)->register(
            new CustomerPageSectionDto('feedback', 'wishlist', WishlistCustomerSection::class, ['feedback::admin_support']),
        );

        // The shop account's My Reviews, My Questions and My Wishlist, always offered (§4.1 F8, owner's Q19).
        $this->app->make(CustomerAccountPages::class)->register(
            new CustomerAccountPageDto('feedback', 'reviews', 'storefront.feedback.reviews', position: 30),
            new CustomerAccountPageDto('feedback', 'questions', 'storefront.feedback.questions', position: 31),
            new CustomerAccountPageDto('feedback', 'wishlist', 'storefront.feedback.wishlist', position: 32),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file: the admin session cookie must be set before "web" opens a session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
