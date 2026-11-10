<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions Content's use cases check (content.md §3), declared into Access's catalog at boot.
 *
 * **Nine jobs**, per store, any role, in the role editor's Content group (round 1 #16, Q12 (a)). Translate
 * has none of its own: it takes the permission of the kind of item being typed (`forKind()`), and a
 * campaign's prices take Pricing's own beside the campaign's.
 *
 * **Reserved**, Super Admins only: themes, checked in every store at once (`allStores()`), and redirects,
 * which belong to no store (store-free). And the system's three jobs - campaigns going live and ending,
 * the sitemap, the redirects' check - store-free, as every module's system jobs are.
 *
 * Each permission's handlers arrive with their PR (plan-content.md).
 */
final class ContentPermissions
{
    /** Home sections and the footer (W1). */
    public const string HOMEPAGE_MANAGE = 'content.homepage.manage';

    /** The announcement bar and smart bars (W2). */
    public const string BAR_MANAGE = 'content.bar.manage';

    /** Pages (W7). */
    public const string PAGE_MANAGE = 'content.page.manage';

    /** Banners (W3). */
    public const string BANNER_MANAGE = 'content.banner.manage';

    /** Campaigns, their notices, linking a theme (W4); S8's audience (round 4 #11). */
    public const string CAMPAIGN_MANAGE = 'content.campaign.manage';

    /** Pop-ups (W6). */
    public const string POPUP_MANAGE = 'content.popup.manage';

    /** Blog posts and categories (W8). */
    public const string BLOG_MANAGE = 'content.blog.manage';

    /** Projects (W9). */
    public const string PROJECT_MANAGE = 'content.project.manage';

    /** SEO defaults, robots lines, rebuilding the sitemap (W10). */
    public const string SEO_MANAGE = 'content.seo.manage';

    /** Themes (W5): reserved, Super Admins, every store at once. */
    public const string THEME_MANAGE = 'content.theme.manage';

    /** Redirects (W11): reserved, Super Admins, store-free. */
    public const string REDIRECT_MANAGE = 'content.redirect.manage';

    /** `AdvanceCampaignsJob`: runs going live and ending, their alerts. */
    public const string CAMPAIGNS_ADVANCE = 'content.campaigns.advance';

    /** `BuildSitemapsJob`. */
    public const string SITEMAP_BUILD = 'content.sitemap.build';

    /** `CheckRedirectsJob`. */
    public const string REDIRECTS_CHECK = 'content.redirects.check';

    /**
     * The kinds of item Translate is pressed in, each with the permission that edits it (§1.3: "the check
     * is by kind, not by item"). A theme is the reserved one, checked in every store.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'homepage' => self::HOMEPAGE_MANAGE,
        'bar' => self::BAR_MANAGE,
        'page' => self::PAGE_MANAGE,
        'banner' => self::BANNER_MANAGE,
        'campaign' => self::CAMPAIGN_MANAGE,
        'popup' => self::POPUP_MANAGE,
        'blog' => self::BLOG_MANAGE,
        'project' => self::PROJECT_MANAGE,
        'seo' => self::SEO_MANAGE,
        'theme' => self::THEME_MANAGE,
    ];

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Content),
            self::jobs(),
        );

        return [
            ...$jobs,
            // Themes span the stores they pick: a Super Admin's, checked in every store (§3).
            new PermissionDefinitionDto(self::THEME_MANAGE, PermissionAudience::Role, reserved: true, kind: PermissionKind::PerStore),
            // Redirects and the system's jobs belong to no store.
            ...array_map(
                static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
                [self::REDIRECT_MANAGE, ...self::system()],
            ),
        ];
    }

    /**
     * @return list<string> the jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [
            self::HOMEPAGE_MANAGE,
            self::BAR_MANAGE,
            self::PAGE_MANAGE,
            self::BANNER_MANAGE,
            self::CAMPAIGN_MANAGE,
            self::POPUP_MANAGE,
            self::BLOG_MANAGE,
            self::PROJECT_MANAGE,
            self::SEO_MANAGE,
        ];
    }

    /**
     * @return list<string> the system's own, never in a role
     */
    public static function system(): array
    {
        return [self::CAMPAIGNS_ADVANCE, self::SITEMAP_BUILD, self::REDIRECTS_CHECK];
    }

    /** The permission that edits a kind of item, or null for a kind Content has not. */
    public static function forKind(string $kind): ?string
    {
        return self::KINDS[$kind] ?? null;
    }
}
