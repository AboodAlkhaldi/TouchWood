<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Redirects;

use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\Repository\RedirectUploads;
use Modules\Content\Domain\Repository\WaitingJobs;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * What W11 and W11b show (content.md §4.4), Super Admins only: the redirects, most visited first, searched by
 * either address - each with its visits, last visit and its check: Check Addresses' last mark, or **Can't Be
 * Reached when a store's code now takes its old address's first word**, read against every store on reading the
 * page; and whether a Check Addresses waits. And a checked sheet's lines, with a count per result.
 */
final readonly class RedirectsHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public const int PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private Redirects $redirects,
        private RedirectUploads $uploads,
        private WaitingJobs $waiting,
        private RedirectRules $rules,
    ) {}

    /**
     * @return array{rows: list<array{oldPath: string, newPath: string, visits: int, lastVisitAt: string|null, check: string|null, finalPath: string|null}>, next: string|null, checkWaits: bool}
     *
     * @throws Unauthorized
     */
    public function list(?string $q, ?string $after): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $page = $this->redirects->page($q, $after, self::PAGE);

        return [
            'rows' => array_map(fn (Redirect $line): array => [
                'oldPath' => $line->oldPath,
                'newPath' => $line->newPath,
                'visits' => $line->visits,
                'lastVisitAt' => $line->lastVisitAt?->format(DATE_ATOM),
                'check' => $this->rules->storeOf(RedirectPath::words($line->oldPath)[0] ?? '') !== null ? Redirect::CANT_BE_REACHED : $line->checkResult,
                'finalPath' => $line->checkFinalPath,
            ], $page['rows']),
            'next' => $page['next'],
            'checkWaits' => $this->waiting->waitingSince(WaitingJobs::CHECK_REDIRECTS) !== null,
        ];
    }

    /**
     * @return array{lines: list<array{line: int, old: string, new: string, result: string, reason: string|null}>, counts: array<string, int>, applied: bool}
     *
     * @throws ContentNotFound|Unauthorized
     */
    public function sheet(string $uploadId): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $upload = $this->uploads->find(strtolower(trim($uploadId)), Moments::now()->modify('-24 hours')) ?? throw new ContentNotFound;
        $counts = ['NEW' => 0, 'SAME' => 0, 'CHANGE' => 0, 'REFUSED' => 0];

        foreach ($upload['lines'] as $line) {
            $counts[$line['result']] = ($counts[$line['result']] ?? 0) + 1;
        }

        return [
            'lines' => array_map(static fn (array $line): array => ['line' => $line['line'], 'old' => $line['old'], 'new' => $line['new'], 'result' => $line['result'], 'reason' => $line['reason']], $upload['lines']),
            'counts' => $counts,
            'applied' => $upload['state'] !== 'CHECKED',
        ];
    }
}
