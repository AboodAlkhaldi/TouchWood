<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ApplyRedirectSheet;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Redirects\RedirectAudit;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Application\Redirects\RedirectSheet;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\ContentLocks;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\Repository\RedirectUploads;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Writes a checked sheet (content.md §1.15, W11b): its New lines added and its Change lines sent to their new pages,
 * each audited; Same and Refused lines left. **It never deletes a line.** The lines are checked again under the
 * redirects' lock first, so what is written is what holds now - a store created since, or a line added by hand,
 * counts. A sheet older than 24 hours, or applied already, is gone.
 */
final readonly class ApplyRedirectSheetHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private RedirectRules $rules,
        private Redirects $redirects,
        private RedirectUploads $uploads,
        private ContentLocks $locks,
        private ContentAccess $access,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return array{added: int, changed: int}
     *
     * @throws ContentNotFound|InvalidContentInput|Unauthorized
     */
    public function handle(ApplyRedirectSheet $command): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $by = $this->access->staffId();

        return $this->db->transaction(function () use ($command, $by): array {
            $this->locks->lock(ContentLocks::ALL_STORES, ContentLocks::REDIRECTS);
            $now = Moments::now();
            $upload = $this->uploads->find(strtolower(trim($command->uploadId)), $now->modify('-24 hours'), lock: true) ?? throw new ContentNotFound;

            if ($upload['state'] !== 'CHECKED') {
                throw new InvalidContentInput('sheet', InvalidContentInput::STATE);
            }

            $written = array_values(array_filter($upload['lines'], static fn (array $line): bool => in_array($line['result'], [RedirectSheet::NEW, RedirectSheet::CHANGE], true)));
            $lines = (new RedirectSheet($this->rules, $this->redirects))->check(array_map(static fn (array $line): array => ['line' => $line['line'], 'old' => $line['typed_old'], 'new' => $line['typed_new']], $written), lock: true);
            $current = $this->redirects->findMany(array_values(array_map(static fn (array $line): string => $line['old'], array_filter($lines, static fn (array $line): bool => $line['result'] === RedirectSheet::CHANGE))), lock: true);
            $counts = ['added' => 0, 'changed' => 0];

            foreach ($lines as $line) {
                if ($line['result'] === RedirectSheet::NEW) {
                    $added = new Redirect($line['old'], $line['new'], 0, null, Redirect::SHEET, null, null, null, $by);
                    $this->redirects->add($added);
                    $this->platform->recordAudit(ContentAudit::entry('content.redirect.added', RedirectAudit::SUBJECT, RedirectAudit::subject($line['old']), null, RedirectAudit::diff(null, $added)));
                    $counts['added']++;
                } elseif ($line['result'] === RedirectSheet::CHANGE && isset($current[$line['old']])) {
                    $changed = $current[$line['old']]->goingTo($line['new']);
                    $this->redirects->update($changed);
                    $this->platform->recordAudit(ContentAudit::entry('content.redirect.changed', RedirectAudit::SUBJECT, RedirectAudit::subject($line['old']), null, RedirectAudit::diff($current[$line['old']], $changed)));
                    $counts['changed']++;
                }
            }

            $this->uploads->applied(strtolower(trim($command->uploadId)), $now);

            return $counts;
        });
    }
}
