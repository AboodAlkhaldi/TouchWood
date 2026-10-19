<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * The audit entries of staff actions on reviews and questions (feedback.md §3):
 * `feedback.review.approved`, `…rejected`, `…unpublished`; `feedback.question.answered`,
 * `…answer_changed`, `…rejected`, `…unpublished` — each with what changed, attribute by attribute.
 */
final class FeedbackAudit
{
    /**
     * @param  array<string, string|null>  $from
     * @param  array<string, string|null>  $to
     */
    public static function entry(string $subject, string $action, string $id, string $storeId, array $from, array $to): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $attribute) {
            if (($from[$attribute] ?? null) !== ($to[$attribute] ?? null)) {
                $changes = $changes->changed($attribute, $from[$attribute] ?? null, $to[$attribute] ?? null);
            }
        }

        return new AuditEntryDto("feedback.{$subject}.{$action}", "feedback.{$subject}", $id, $storeId, $changes);
    }
}
