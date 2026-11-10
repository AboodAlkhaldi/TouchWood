<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\AddRedirect\AddRedirect;
use Modules\Content\Application\Command\AddRedirect\AddRedirectHandler;
use Modules\Platform\Application\Query\StoreDirectory;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/** What the redirects' tests share (plan PR 4): lines added as a Super Admin, stores switched off and on. */
final class ContentRedirects
{
    /** Acting as a Super Admin from here on: redirects are theirs alone (content.md §3). */
    public static function superAdmin(): string
    {
        $staffId = Fx::staff(superAdmin: true);
        Fx::actAsStaff($staffId);

        return $staffId;
    }

    /** A line added through its use case, as a Super Admin, the actor of the test put back after. */
    public static function line(string $old, string $new): string
    {
        $before = app(ActorContext::class)->current();
        self::superAdmin();
        $kept = app(AddRedirectHandler::class)->handle(new AddRedirect($old, $new));
        Fx::actAs($before);

        return $kept;
    }

    /** A store switched off or on behind the use cases, the store directory told. Its id read first (lesson 134). */
    public static function storeOn(string $code, bool $on): string
    {
        $id = (string) DB::table('platform.stores')->where('code', $code)->value('id');
        DB::table('platform.stores')->where('id', $id)->update(['is_active' => $on]);
        app(StoreDirectory::class)->invalidate();

        return $id;
    }
}
