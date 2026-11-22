<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Model;

use Modules\Ops\Domain\Exception\InvalidOpsInput;

/**
 * Who was answered by the WhatsApp number's auto-reply (ops.md §9.8 point 5): never the number, only a keyed HMAC of
 * it, 64 lower-case hex characters (SHA-256). A plain hash of a phone number could be reversed by trying every
 * number; the key comes from APP_KEY, so the table alone reveals nothing. `ops.whatsapp_auto_replies`' CHECK is the
 * last line of this rule.
 */
final readonly class AutoReplySender
{
    /** @throws InvalidOpsInput */
    public function __construct(
        public string $hmac,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $hmac) !== 1) {
            throw new InvalidOpsInput('sender', '64 lower-case hexadecimal characters');
        }
    }
}
