<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use DateTimeImmutable;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Public\Enums\MessageChannel;
use Modules\Ops\Public\Enums\MessageKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * The record of one email or SMS (ops.md §5): its kind, the person when known, the store when there
 * is one, the channel, the provider's id, its status.
 *
 * **Never a code or a link** (access.md §2.3): there is no field for the text, the address or anything
 * a message carried. Every rule of `ops.sent_messages`' CHECKs is refused here first.
 */
final readonly class SentMessage
{
    public const int PROVIDER_ID_MAX = 255;

    /** @throws InvalidOpsInput */
    public function __construct(
        public string $id,
        public MessageKind $kind,
        public ?string $customerId,
        public ?string $staffId,
        public ?StoreId $store,
        public MessageChannel $channel,
        public ?string $providerId,
        public SendStatus $status,
        public DateTimeImmutable $createdAt,
    ) {
        if ($customerId !== null && $staffId !== null) {
            throw new InvalidOpsInput('person', 'a customer or a staff member, not both');
        }

        if ($channel === MessageChannel::Bell) {
            throw new InvalidOpsInput('channel', 'a bell entry is not a sent message');
        }

        if (! in_array($channel, $kind->channels(), true)) {
            throw new InvalidOpsInput('channel', 'not a channel of this kind');
        }

        if ($providerId !== null && (mb_strlen($providerId) < 1 || mb_strlen($providerId) > self::PROVIDER_ID_MAX)) {
            throw new InvalidOpsInput('provider_id', 'between 1 and '.self::PROVIDER_ID_MAX.' characters');
        }
    }
}
