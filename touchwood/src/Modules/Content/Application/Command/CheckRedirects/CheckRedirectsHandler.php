<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\CheckRedirects;

use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Redirects\AddressProbe;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\Repository\WaitingJobs;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Check Addresses, a page at a time (content.md §1.15, §9.3 #30): **each line's new address is asked as a visitor
 * would ask it** and the line marked - **Fine** (its page answers), **Forwards to** (a slug changed: a second hop,
 * with the address it ends on for Use Final Address), **Not Found**, or **Store Off** (its store is off: not asked).
 * A line whose old address a store's code took since is marked on reading the list, not here.
 *
 * The system's, reserved (`content.redirects.check`). An address that gave no answer (the site unreachable, a
 * time-out, a server error) keeps its last mark. A mark is written only if the line still goes where it was asked.
 */
final readonly class CheckRedirectsHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECTS_CHECK;

    /** Lines asked a job: a page stays well inside a worker's time. */
    public const int PAGE = 100;

    /** Redirects followed at most to find where an address ends. */
    public const int HOPS = 5;

    public function __construct(
        private Authorizer $authorizer,
        private Redirects $redirects,
        private WaitingJobs $waiting,
        private AddressProbe $probe,
        private RedirectRules $rules,
    ) {}

    /**
     * @return string|null the old address the next page starts after, or null when every line is asked
     *
     * @throws Unauthorized
     */
    public function handle(CheckRedirects $command): ?string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        if ($command->after === null) {
            // Under way: a press from now on queues another.
            $this->waiting->started(WaitingJobs::CHECK_REDIRECTS);
        }

        $lines = $this->redirects->after($command->after, self::PAGE);

        foreach ($lines as $line) {
            $found = $this->check($line->newPath);

            if ($found !== null) {
                $this->redirects->mark($line->oldPath, $line->newPath, $found[0], $found[1], Moments::now());
            }
        }

        return count($lines) < self::PAGE ? null : $lines[array_key_last($lines)]->oldPath;
    }

    /**
     * @return array{0: string, 1: string|null}|null the mark and, for one that forwards, where it ends; null for no answer
     */
    private function check(string $newPath): ?array
    {
        $store = $this->rules->storeOf(RedirectPath::words($newPath)[0] ?? '');

        if ($store === null || ! $store->isActive) {
            return [Redirect::STORE_OFF, null];
        }

        $path = $newPath;

        for ($hop = 0; $hop <= self::HOPS; $hop++) {
            $answer = $this->probe->answer($path);

            if ($answer === null || $answer['status'] >= 500) {
                return null;
            }

            if (in_array($answer['status'], [301, 302, 303, 307, 308], true) && $answer['location'] !== null && $hop < self::HOPS) {
                $path = $answer['location'];

                continue;
            }

            if ($answer['status'] >= 200 && $answer['status'] < 300) {
                return $path === $newPath ? [Redirect::FINE, null] : [Redirect::FORWARDS, $path];
            }

            return [Redirect::NOT_FOUND, null];
        }

        return [Redirect::NOT_FOUND, null];
    }
}
