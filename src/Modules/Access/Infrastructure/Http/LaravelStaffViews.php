<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\StaffView\StaffViewPass;
use Modules\Access\Application\StaffView\StaffViews;
use Shared\Domain\ValueObject\StoreId;
use WeakMap;

/**
 * The staff view's passes (spec §1.11): a row in `access.staff_views` and its token in the browser's
 * own cookie - path `/`, so the shop reads it; HTTP-only; for this browser session only.
 *
 * **The path is given every time**, never left to Laravel: the cookie helpers take the path of the
 * request's session, and an admin request's is `/admin`, where the shop would never see the pass
 * (lesson 91: the theme cookie written at /admin that the shop never read).
 *
 * Bound per request: what a request's pass is, is read once and kept for that request only.
 */
final class LaravelStaffViews implements StaffViews
{
    public const string COOKIE = 'tw_staff_view';

    private const string TABLE = 'access.staff_views';

    /** How often a pass's last-seen time is written while it is used: a minute is idle's grain. */
    private const int SEEN_EVERY_SECONDS = 60;

    /**
     * Each request's pass, read once for that request and never another's: keyed by the request
     * itself, because an instance bound per request was seen to outlive one (a feature test's run of
     * requests kept the first one's answer, and its idle clock never moved).
     *
     * @var WeakMap<Request, StaffViewPass|null>
     */
    private WeakMap $passes;

    public function __construct(
        private readonly Container $app,
        private readonly Connection $db,
        private readonly GrantsReader $grants,
        private readonly StaffSecuritySettings $settings,
    ) {
        $this->passes = new WeakMap;
    }

    public function open(string $staffId, StoreId $store, int $sessionVersion): void
    {
        $request = $this->request();
        $session = $request->hasSession() ? $request->session() : null;
        $signedIn = $session?->get(LaravelStaffSessions::SIGNED_IN);

        if ($session === null || ! is_array($signedIn) || ($signedIn['id'] ?? null) !== $staffId || ! is_int($signedIn['signed_in_at'] ?? null)) {
            throw new LogicException('A staff view opens from the admin session of the staff member it is for.');
        }

        $adminSession = self::hash($session->getId());
        ['token' => $token, 'hash' => $hash] = SecretTokens::issue();
        $now = CarbonImmutable::now();
        $oldest = $now->subHours($this->settings->sessionMaxHours());

        $this->db->transaction(function () use ($staffId, $store, $sessionVersion, $signedIn, $adminSession, $hash, $now, $oldest): void {
            // Anybody's passes past the maximum go - a closed browser never comes back to end its own.
            $this->db->table(self::TABLE)->where('signed_in_at', '<', $oldest)->delete();

            // This admin session's pass, new or replacing the one it opened before, in one statement:
            // two presses at once would otherwise both delete and both insert, and one would fail.
            $this->db->table(self::TABLE)->upsert([[
                'id' => strtolower((string) Str::ulid()),
                'staff_user_id' => $staffId,
                'token_hash' => $hash,
                'admin_session_hash' => $adminSession,
                'store_id' => $store->value,
                'session_version' => $sessionVersion,
                'signed_in_at' => CarbonImmutable::createFromTimestamp($signedIn['signed_in_at']),
                'seen_at' => $now,
                'created_at' => $now,
            ]], ['staff_user_id', 'admin_session_hash'], ['token_hash', 'store_id', 'session_version', 'signed_in_at', 'seen_at', 'created_at']);
        });

        $cookies = $this->cookies();
        // Minutes 0: gone when the browser closes, whatever the row says.
        $cookies->queue($cookies->make(self::COOKIE, $token, 0, '/', null, null, true, false, 'lax'));
    }

    public function current(): ?StaffViewPass
    {
        $request = $this->request();

        if ($this->passes->offsetExists($request)) {
            return $this->passes[$request];
        }

        return $this->passes[$request] = $this->read($request);
    }

    private function read(Request $request): ?StaffViewPass
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        // With the name the shop's person menu shows, in the same read.
        $row = $this->db->table(self::TABLE.' as views')
            ->join('access.staff_users as staff', 'staff.id', '=', 'views.staff_user_id')
            ->where('views.token_hash', SecretTokens::hash($token))
            ->first(['views.*', 'staff.first_name', 'staff.last_name']);

        if ($row === null) {
            $this->forgetCookie();

            return null;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $signedInAt = CarbonImmutable::parse((string) $row->signed_in_at)->getTimestamp();
        $seenAt = CarbonImmutable::parse((string) $row->seen_at)->getTimestamp();
        $grants = $this->grants->forStaff((string) $row->staff_user_id);

        $over = $grants === null || ! $grants->isActive() || $grants->sessionVersion !== (int) $row->session_version
            || $now - $signedInAt > $this->settings->sessionMaxHours() * 3600
            || $now - $seenAt > $this->settings->sessionIdleMinutes() * 60
            || ! $this->adminSessionLives((string) $row->staff_user_id, (string) $row->admin_session_hash);

        if ($over) {
            $this->db->table(self::TABLE)->where('id', $row->id)->delete();
            $this->forgetCookie();

            return null;
        }

        if ($now - $seenAt >= self::SEEN_EVERY_SECONDS) {
            $this->db->table(self::TABLE)->where('id', $row->id)->update(['seen_at' => CarbonImmutable::createFromTimestamp($now)]);
        }

        return new StaffViewPass(
            (string) $row->staff_user_id,
            trim(((string) $row->first_name).' '.((string) $row->last_name)),
            (string) $row->store_id,
        );
    }

    public function endHere(): void
    {
        $request = $this->request();
        $token = $request->cookie(self::COOKIE);

        if (is_string($token) && $token !== '') {
            $this->db->table(self::TABLE)->where('token_hash', SecretTokens::hash($token))->delete();
            $this->forgetCookie();
        }

        $this->passes[$request] = null;
    }

    public function endForThisAdminSession(): void
    {
        $request = $this->request();

        if ($request->hasSession()) {
            $this->db->table(self::TABLE)->where('admin_session_hash', self::hash($request->session()->getId()))->delete();
        }
    }

    public function endAllOf(string $staffId): void
    {
        $this->db->table(self::TABLE)->where('staff_user_id', $staffId)->delete();
    }

    /**
     * Whether the admin session the pass came from still has its row: gone once it is signed out,
     * ended from another device, replaced by signing in again, or ended everywhere (review of P6).
     * The panel always keeps its sessions in this table (UseAdminSession), whatever the site's
     * driver; the pass holds only a hash of the id, so the staff member's few rows are compared.
     */
    private function adminSessionLives(string $staffId, string $adminSessionHash): bool
    {
        foreach ($this->db->table('access.admin_sessions')->where('user_id', $staffId)->pluck('id') as $id) {
            if (hash_equals($adminSessionHash, self::hash((string) $id))) {
                return true;
            }
        }

        return false;
    }

    private function forgetCookie(): void
    {
        $cookies = $this->cookies();
        $cookies->queue($cookies->forget(self::COOKIE, '/'));
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function request(): Request
    {
        return $this->app->make(Request::class);
    }

    private function cookies(): CookieJar
    {
        return $this->app->make('cookie');
    }
}
