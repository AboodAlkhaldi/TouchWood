<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

use Modules\Content\Application\ContentAudit;
use Modules\Content\Domain\Model\Redirect;
use Modules\Platform\Public\Dto\AuditChanges;

/**
 * A redirect's audit entry (content.md §1.1 rule 13): store-free, both addresses by value. The log names a subject
 * by an id of 64 characters at most, and an old address may run to 2,000, so a line is named by its address's
 * SHA-1 - the same address, the same subject - and the address itself is in the changes.
 */
final class RedirectAudit
{
    public const string SUBJECT = 'content.redirect';

    public static function subject(string $oldPath): string
    {
        return sha1($oldPath);
    }

    /**
     * @param  Redirect|null  $before  null for a line added
     * @param  Redirect|null  $after  null for a line deleted
     */
    public static function diff(?Redirect $before, ?Redirect $after): AuditChanges
    {
        return ContentAudit::diff($before?->fields() ?? [], $after?->fields() ?? []);
    }
}
