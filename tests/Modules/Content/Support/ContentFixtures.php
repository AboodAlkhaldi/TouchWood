<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SaveBanner\SaveBanner;
use Modules\Content\Application\Command\SaveBanner\SaveBannerHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\SavePopup\SavePopup;
use Modules\Content\Application\Command\SavePopup\SavePopupHandler;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * What Content's tests share (plan PR 3): a staff member holding one of Content's jobs in a store, public images in
 * each state of their sizes, the store's own moments typed as staff type them, and drafts made through the use cases.
 */
final class ContentFixtures
{
    /**
     * Acting as a staff member holding these jobs in these stores, and nothing else.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $stores
     */
    public static function editor(array $permissions, array $stores = ['sa']): string
    {
        $staffId = Fx::staffWith($permissions, $stores);
        Fx::actAsStaff($staffId);

        return $staffId;
    }

    public static function store(string $code = 'sa'): StoreDto
    {
        return app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId($code))) ?? throw new \LogicException('No such store.');
    }

    /**
     * A media row as Platform writes one, every column (lesson 123): a public JPEG whose sizes are ready, unless told.
     *
     * @param  string  $sizes  READY, PENDING or FAILED
     */
    public static function image(string $sizes = 'READY', string $visibility = 'PUBLIC', string $mime = 'image/jpeg'): string
    {
        $id = strtolower((string) Str::ulid());
        $now = CarbonImmutable::now();
        $public = $visibility === 'PUBLIC' && str_starts_with($mime, 'image/');

        DB::table('platform.media')->insert([
            'id' => $id,
            'visibility' => $visibility,
            'disk' => 'local',
            'object_key' => "media/{$id}.jpg",
            'original_filename' => 'photo.jpg',
            'mime' => $mime,
            'bytes' => 1000,
            'width' => 10,
            'height' => 10,
            'checksum' => hash('sha256', $id),
            'variants_status' => $public ? $sizes : null,
            'variants_queued_at' => $public ? $now : null,
            'variants_generated_at' => $public && $sizes === 'READY' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /** Now, as the code reads it (`Moments::now`). */
    public static function now(): \DateTimeImmutable
    {
        return Moments::now();
    }

    /** A moment as staff type it in the store's zone, so many minutes from now. */
    public static function typed(int $minutes, string $code = 'sa'): string
    {
        return CarbonImmutable::now()->addMinutes($minutes)->setTimezone(self::store($code)->timezone)->format('Y-m-d H:i');
    }

    /** Both clocks on, past the next whole minute (typed moments are to the minute). */
    public static function clockOn(int $minutes): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes));
        Carbon::setTestNow(CarbonImmutable::now());
    }

    /**
     * A draft page through its use case, as its editor.
     *
     * @param  array<string, mixed>  $with  DocumentInput's arguments to change
     */
    public static function page(array $with = [], string $code = 'sa'): string
    {
        $n = self::next();

        return app(SavePageHandler::class)->handle(new SavePage(new DocumentInput(...[
            'id' => null,
            'storeId' => Fx::storeId($code),
            'version' => 0,
            'titleAr' => "صفحة {$n}",
            'titleEn' => "Page {$n}",
            'bodyAr' => 'نص الصفحة',
            'bodyEn' => 'The page text',
            ...$with,
        ])));
    }

    /**
     * A draft policy through its use case, as its editor (build 4): Terms and Conditions in the store, both languages.
     *
     * @param  array<string, mixed>  $with  PolicyInput's arguments to change
     * @param  list<string>  $codes  the stores it serves, by code
     */
    public static function policy(array $with = [], array $codes = ['sa']): string
    {
        $n = self::next();

        return app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[
            'id' => null,
            'kind' => 'TERMS',
            'storeIds' => array_map(static fn (string $code): string => Fx::storeId($code), $codes),
            'version' => 0,
            'titleAr' => "سياسة {$n}",
            'titleEn' => "Policy {$n}",
            'bodyAr' => 'نص السياسة',
            'bodyEn' => 'The policy text',
            ...$with,
        ])));
    }

    /**
     * A policy of this kind published in these stores through its use cases (build 4), for the modules that ask
     * Platform's `ShopPolicies` - Sales' checkout, Access's sign-up, B2B's application. As whoever acts, who must
     * hold `content.policy.manage` in those stores (a Super Admin does). Its titles and addresses are unique, so a
     * suite that keeps its data (the browser's) never meets one twice.
     *
     * @param  list<string>  $codes
     * @return string the policy's id
     */
    public static function publishedPolicy(string $kind = 'TERMS', array $codes = ['sa']): string
    {
        $mark = (string) random_int(10000000, 99999999);
        $words = ['TERMS' => 'Terms and Conditions', 'PRIVACY' => 'Privacy Policy', 'RETURNS' => 'Returns and Refunds', 'COMPANY_TERMS' => 'Company Terms'][$kind] ?? 'Policy';
        $id = self::policy([
            'kind' => $kind, 'titleAr' => "سياسة {$mark}", 'titleEn' => "{$words} {$mark}",
            'slugAr' => "سياسة-{$mark}", 'slugEn' => strtolower(str_replace(' ', '-', $words))."-{$mark}",
        ], $codes);
        app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, (int) DB::table('content.policies')->where('id', $id)->value('version')));

        return $id;
    }

    /**
     * A draft banner through its use case, as its editor: a home phone banner with its image ready.
     *
     * @param  array<string, mixed>  $with  SaveBanner's arguments to change
     */
    public static function banner(array $with = [], string $code = 'sa'): string
    {
        return app(SaveBannerHandler::class)->handle(new SaveBanner(...[
            'bannerId' => null,
            'storeId' => Fx::storeId($code),
            'version' => 0,
            'placement' => 'HOME_HERO',
            'categoryId' => null,
            'name' => 'Summer',
            'link' => '/categories/summer',
            'hasWords' => false,
            'altAr' => 'تخفيضات الصيف',
            'altEn' => 'Summer sale',
            'startsAt' => null,
            'endsAt' => null,
            'images' => ['DESKTOP:any' => self::image(), 'PHONE:any' => self::image()],
            ...$with,
        ]));
    }

    /**
     * A draft pop-up through its use case, as its editor.
     *
     * @param  array<string, mixed>  $with  SavePopup's arguments to change
     */
    public static function popup(array $with = [], string $code = 'sa'): string
    {
        return app(SavePopupHandler::class)->handle(new SavePopup(...[
            'popupId' => null,
            'storeId' => Fx::storeId($code),
            'version' => 0,
            'name' => 'Welcome',
            'titleAr' => 'أهلًا بك',
            'titleEn' => 'Welcome',
            'textAr' => 'خصم على أول طلب',
            'textEn' => 'A discount on your first order',
            'buttonAr' => 'تسوق',
            'buttonEn' => 'Shop',
            'link' => '/categories/kitchens',
            'delaySeconds' => null,
            'mediaId' => null,
            'startsAt' => null,
            'endsAt' => null,
            ...$with,
        ]));
    }

    /** Turns a store off; its id is taken first, an off store being no store by its code (lesson 187). */
    public static function switchOff(string $code): string
    {
        $id = Fx::storeId($code);
        DB::table('platform.stores')->where('id', $id)->update(['is_active' => false]);
        app(StoreDirectory::class)->invalidate();

        return $id;
    }

    private static int $next = 0;

    private static function next(): int
    {
        return ++self::$next;
    }
}
