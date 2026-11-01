<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Model\StaffMessage;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * What staff write to customers, in Platform's audit log (owner, 2026-10-30): a notice created,
 * changed or cancelled; a staff message sent, to one customer, the store or a segment. Written in the same
 * transaction as the change; Platform adds who and when. The text's titles, its page, the channels,
 * the time - and the message's audience; never a customer's personal data, only their id.
 */
final class WrittenAudit
{
    public const string NOTICE = 'ops.notice';

    public const string STAFF_MESSAGE = 'ops.staff_message';

    public static function noticeCreated(Notice $notice): AuditEntryDto
    {
        return self::notice('ops.notice.created', null, $notice);
    }

    public static function noticeChanged(Notice $before, Notice $after): AuditEntryDto
    {
        return self::notice('ops.notice.changed', $before, $after);
    }

    public static function noticeCancelled(Notice $before, Notice $after): AuditEntryDto
    {
        return self::notice('ops.notice.cancelled', $before, $after);
    }

    public static function staffMessageSent(StaffMessage $message): AuditEntryDto
    {
        $changes = AuditChanges::none()
            ->changed('audience', null, $message->audience())
            ->changed('customer_id', null, $message->customerId)
            ->changed('segment', null, $message->segmentId)
            ->changed('title_ar', null, $message->text->titleAr)
            ->changed('title_en', null, $message->text->titleEn)
            ->changed('channels', null, self::channels($message->byBell, $message->byEmail));

        return new AuditEntryDto('ops.staff_message.sent', self::STAFF_MESSAGE, strtolower($message->id), $message->store->value, $changes);
    }

    private static function notice(string $action, ?Notice $before, Notice $after): AuditEntryDto
    {
        $from = $before === null ? [] : self::values($before);
        $to = self::values($after);
        $changes = AuditChanges::none();

        foreach ($to as $attribute => $value) {
            if (($from[$attribute] ?? null) !== $value) {
                $changes = $changes->changed($attribute, $from[$attribute] ?? null, $value);
            }
        }

        return new AuditEntryDto($action, self::NOTICE, $after->id, $after->store->value, $changes);
    }

    /** @return array<string, string|null> */
    private static function values(Notice $notice): array
    {
        return [
            'title_ar' => $notice->text->titleAr,
            'title_en' => $notice->text->titleEn,
            'body_ar' => $notice->text->bodyAr,
            'body_en' => $notice->text->bodyEn,
            'link' => $notice->text->link,
            'channels' => self::channels($notice->byBell, $notice->byEmail),
            'send_at' => self::utc($notice->sendAt),
            'status' => $notice->status->value,
            'segment' => $notice->segmentId,
        ];
    }

    /** The channels as one value - "BELL", "EMAIL" or "BELL EMAIL": a name with "email" in it reads as personal data to the log. */
    private static function channels(bool $bell, bool $email): string
    {
        return implode(' ', [...($bell ? ['BELL'] : []), ...($email ? ['EMAIL'] : [])]);
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }
}
