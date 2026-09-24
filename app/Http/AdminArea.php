<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The admin panel as a place a module can put a page (frontend.md §4.1).
 *
 * The panel is one area with one door: the admin session, then who is asking, then what travels
 * with every page, and - behind the sign-in - a staff member who is actually signed in. **Access
 * implements all four**, because identity is Access's subject, and registers each middleware under
 * the name given here.
 *
 * The names live in the glue rather than in Access because every module after Access needs them.
 * Platform's stores screen, and Catalog's and Sales's after it, sit in this panel, and a module may
 * not reach into Access to find out how - the dependency only runs the other way (deptrac.yaml).
 * So the area is named where anybody's Presentation layer may read it, and the module that
 * implements it names its middleware from here too: one list, not two that must be kept in step.
 */
final class AdminArea
{
    /** Every admin page hangs under /admin. Platform reserves the path so no store can take it. */
    public const string PREFIX = 'admin';

    /** The panel's own session cookie, set before `web` opens the session. */
    public const string SESSION = 'access.admin-session';

    /** Who is asking - which may be nobody, on the sign-in screen itself. */
    public const string IDENTIFY = 'access.identify-staff';

    /** What travels with every page of the panel: the viewer, the menu, the store, the theme. */
    public const string PAGE = 'admin.page';

    /** And this on top for anything behind the sign-in: a staff member who really is signed in. */
    public const string SIGNED_IN = 'access.staff';

    /**
     * What every admin page passes through, signed in or not, in this order.
     *
     * @var list<string>
     */
    public const array MIDDLEWARE = [self::SESSION, 'web', self::IDENTIFY, self::PAGE];
}
