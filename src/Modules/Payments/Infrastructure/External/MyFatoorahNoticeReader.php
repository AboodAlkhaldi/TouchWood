<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use JsonException;
use Modules\Payments\Application\Gateway\NoticeReader;
use Modules\Payments\Application\Gateway\NoticeReading;

/**
 * MyFatoorah's webhook v2 (notes §5.4): the five fields of `PAYMENT_STATUS_CHANGED` in their documented order,
 * as `name=value,...`, a missing value as the empty text, HMAC-SHA256 with the portal's secret, Base64. The
 * amount is not signed, so nothing here is believed: the attempt asks (rule 1).
 */
final class MyFatoorahNoticeReader implements NoticeReader
{
    private const int PAYMENT_STATUS_CHANGED = 1;

    public function read(string $body, ?string $signature, #[\SensitiveParameter] array $secrets): NoticeReading
    {
        if ($signature === null || $signature === '' || $secrets === []) {
            return new NoticeReading(false);
        }

        try {
            $notice = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return new NoticeReading(false);
        }

        if (! is_array($notice)) {
            return new NoticeReading(false);
        }

        // Only this kind has the signature we know how to check, and we read no other: thanked and dropped,
        // so it is not sent again.
        if ((self::path($notice, 'Event', 'Code') ?? '') !== (string) self::PAYMENT_STATUS_CHANGED) {
            return new NoticeReading(true);
        }

        $signed = implode(',', [
            'Invoice.Id='.(self::path($notice, 'Data', 'Invoice', 'Id') ?? ''),
            'Invoice.Status='.(self::path($notice, 'Data', 'Invoice', 'Status') ?? ''),
            'Transaction.Status='.(self::path($notice, 'Data', 'Transaction', 'Status') ?? ''),
            'Transaction.PaymentId='.(self::path($notice, 'Data', 'Transaction', 'PaymentId') ?? ''),
            'Invoice.ExternalIdentifier='.(self::path($notice, 'Data', 'Invoice', 'ExternalIdentifier') ?? ''),
        ]);

        foreach ($secrets as $secret) {
            if (hash_equals(base64_encode(hash_hmac('sha256', $signed, $secret, true)), $signature)) {
                $reference = self::path($notice, 'Event', 'Reference');

                return new NoticeReading(true, $reference === '' ? null : $reference, MyFatoorahScrub::clean($notice));
            }
        }

        return new NoticeReading(false);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function path(array $data, string ...$names): ?string
    {
        $value = $data;

        foreach ($names as $name) {
            if (! is_array($value) || ! array_key_exists($name, $value)) {
                return null;
            }

            $value = $value[$name];
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
