<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Modules\Payments\Application\Gateway\ChargeAnswer;
use Modules\Payments\Application\Gateway\ChargeRequest;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\OpenedSession;
use Modules\Payments\Application\Gateway\PaymentAnswer;
use Modules\Payments\Application\Gateway\SessionAnswer;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Model\GatewayCall;
use Modules\Payments\Domain\Repository\GatewayCalls;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MyFatoorah's API, v3 (payments.md §1.3; notes §0): a 30-second timeout, a status asked three times five
 * seconds apart (notes §0 #16), every try logged with the key never in it (rule 13). A 401 is the key's, or a
 * permission it lacks when MyFatoorah says so; another 4xx, or an answer saying it failed, is MyFatoorah's no.
 * No answer, a 5xx, a redirect, a 4xx that says "not now" (408, 409, 423, 425, 429) or an answer we cannot read
 * means we do not know, and is never taken for a no.
 */
final readonly class MyFatoorahClient implements GatewayClient
{
    private const int TIMEOUT_SECONDS = 30;

    private const int STATUS_TRIES = 3;

    private const int SECONDS_BETWEEN_TRIES = 5;

    /** The session always names them: one switched on later never appears by itself (§1.3). */
    private const array METHODS = ['card', 'applepay', 'stcpay'];

    private const array CARD_NETWORKS = ['visa', 'masterCard', 'mada'];

    /** MyFatoorah's own words for a key without one endpoint's permission (notes §0 #18). */
    private const string NO_PERMISSION = 'does not have the required permissions';

    /** A 4xx that says "not now" rather than "no": what it answered may still be going on. */
    private const array NOT_NOW = [408, 409, 423, 425, 429];

    private const int LONGEST_SESSION_ID = 100;

    private const int LONGEST_ENDPOINT = 300;

    public function __construct(
        private Http $http,
        private GatewayCalls $calls,
        private LoggerInterface $logger,
    ) {}

    public function openSession(GatewayAccess $access, string $amount, string $currencyCode, string $locale, DateTimeImmutable $expiresAt): OpenedSession
    {
        $answer = $this->send($access, 'POST', '/v3/sessions', [
            'PaymentMode' => 'COLLECT_DETAILS',
            // A JSON number; written back in its shortest exact form (serialize_precision -1).
            'Order' => ['Amount' => (float) $amount, 'Currency' => $currencyCode],
            'OperationType' => 'PAY',
            'SupportedNetworks' => self::CARD_NETWORKS,
            'SupportedPaymentMethods' => self::METHODS,
            'SessionExpiry' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'Language' => strtolower($locale) === 'ar' ? 'AR' : 'EN',
        ], 1);

        $data = self::data($answer);
        $id = self::text($data, 'SessionId');

        if ($id === null || strlen($id) > self::LONGEST_SESSION_ID) {
            throw new GatewayUnreachable;
        }

        return new OpenedSession($id, self::moment(self::text($data, 'SessionExpiry')) ?? $expiresAt);
    }

    public function charge(GatewayAccess $access, ChargeRequest $request): ChargeAnswer
    {
        $answer = self::data($this->send($access, 'POST', '/v3/payments', [
            'SourceOfFund' => ['SessionId' => $request->sessionId],
            'Order' => ['Amount' => (float) $request->amount, 'Currency' => $request->currencyCode, 'ExternalIdentifier' => $request->attemptId],
            'OperationType' => 'PAY',
            'PaymentExpiry' => $request->paymentExpiry->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'Customer' => ['Reference' => $request->ownerId],
            'IntegrationUrls' => ['Redirection' => $request->returnUrl, 'Webhook' => $request->noticeUrl],
            'Language' => strtolower($request->locale) === 'ar' ? 'AR' : 'EN',
        ], self::STATUS_TRIES, ['Idempotency-Key' => $request->idempotencyKey], true));

        [$invoiceId, $paymentId] = [self::text($answer, 'InvoiceId'), self::text($answer, 'PaymentId')];

        // It said yes and we cannot read to what: the session will tell.
        if ($invoiceId === null || $paymentId === null) {
            throw new GatewayUnreachable;
        }

        return new ChargeAnswer($invoiceId, $paymentId, self::text($answer, 'PaymentURL'));
    }

    public function paymentDetails(GatewayAccess $access, string $paymentId): PaymentAnswer
    {
        return self::payment(self::data($this->send($access, 'GET', '/v3/payments/'.rawurlencode($paymentId), null, self::STATUS_TRIES)))
            ?? throw new GatewayUnreachable;
    }

    public function sessionDetails(GatewayAccess $access, string $sessionId): SessionAnswer
    {
        $answer = self::data($this->send($access, 'GET', '/v3/sessions/'.rawurlencode($sessionId), null, self::STATUS_TRIES));
        $isUsed = $answer['IsUsed'] ?? null;

        if (! is_bool($isUsed)) {
            throw new GatewayUnreachable;
        }

        $result = $answer['TransactionResult'] ?? null;
        $payment = is_array($result) && $result !== [] ? self::payment($result) : null;

        // "Never charged" releases a try (rule 15), so only a clean "unused, nothing to show" may say it.
        if ($payment === null && ($isUsed || ! in_array($result, [null, []], true))) {
            throw new GatewayUnreachable;
        }

        return new SessionAnswer($isUsed, $payment);
    }

    public function registerApplePayDomain(GatewayAccess $access, string $domain): void
    {
        // Listing a domain says the form may offer Apple Pay on it: only a clear yes does that.
        if (self::said($this->send($access, 'POST', '/v2/RegisterApplePayDomain', ['DomainName' => $domain], 1)) !== true) {
            throw new GatewayUnreachable;
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @param  bool  $repeatRefusalIsUnknown  a refusal after a repeat says nothing of the first call (rule 4)
     * @return array<array-key, mixed> the whole answer
     *
     * @throws GatewayKeyRefused|KeyPermissionMissing|GatewayRefused|GatewayUnreachable
     */
    private function send(GatewayAccess $access, string $method, string $path, ?array $body, int $tries, array $headers = [], bool $repeatRefusalIsUnknown = false): array
    {
        for ($try = 1; ; $try++) {
            $response = $this->try($access, $method, $path, $body, $headers);

            if ($response !== null) {
                $status = $response->status();

                if ($repeatRefusalIsUnknown && $try > 1 && $status >= 400 && $status < 500 && ! in_array($status, self::NOT_NOW, true)) {
                    throw new GatewayUnreachable;
                }

                if ($status === 401) {
                    throw str_contains($response->body(), self::NO_PERMISSION) ? new KeyPermissionMissing($method.' '.$path) : new GatewayKeyRefused;
                }

                $answer = $status >= 500 || in_array($status, self::NOT_NOW, true) ? null : self::decoded($response);

                if ($answer !== null) {
                    if ($status >= 400 || self::said($answer) === false) {
                        throw new GatewayRefused(self::words($answer));
                    }

                    if ($status < 300) {
                        return $answer;
                    }
                }
            }

            if ($try >= $tries) {
                throw new GatewayUnreachable;
            }

            Sleep::for(self::SECONDS_BETWEEN_TRIES)->seconds();
        }
    }

    /**
     * One try, logged whatever came of it.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function try(GatewayAccess $access, string $method, string $path, ?array $body, array $headers): ?Response
    {
        $started = hrtime(true);
        $endpoint = $method.' '.$path;
        $response = null;
        $error = null;

        try {
            $request = $this->http->withToken($access->key)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()->withHeaders($headers);
            $response = $method === 'GET' ? $request->get($access->apiBase.$path) : $request->post($access->apiBase.$path, $body ?? []);
        } catch (ConnectionException) {
            // Its message names the address and nothing of the key; ours names no more than it must.
            $error = 'No answer';
        }

        // A call that was made must keep its answer, even when writing it down fails.
        try {
            $this->calls->record(new GatewayCall(
                $access->storeId,
                $access->attemptId,
                mb_substr($endpoint, 0, self::LONGEST_ENDPOINT),
                $body === null ? null : MyFatoorahScrub::clean($body),
                $response?->status(),
                $response === null ? null : (is_array($answer = self::decoded($response)) ? MyFatoorahScrub::clean($answer) : null),
                $error,
                (int) ((hrtime(true) - $started) / 1_000_000),
            ));
        } catch (Throwable $failure) {
            $this->logger->error('A gateway call could not be logged.', ['store_id' => $access->storeId, 'endpoint' => $endpoint, 'error' => $failure::class]);
        }

        return $response;
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @return array<array-key, mixed>
     */
    private static function data(array $answer): array
    {
        return is_array($answer['Data'] ?? null) ? $answer['Data'] : [];
    }

    /**
     * `IsSuccess`, which the docs write both as a boolean and as text (notes §9). Null when it says neither.
     *
     * @param  array<array-key, mixed>  $answer
     */
    private static function said(array $answer): ?bool
    {
        $value = $answer['IsSuccess'] ?? null;

        return is_bool($value) ? $value : match (is_string($value) ? strtolower($value) : null) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private static function moment(?string $text): ?DateTimeImmutable
    {
        try {
            return $text === null ? null : CarbonImmutable::parse($text)->toDateTimeImmutable();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decoded(Response $response): ?array
    {
        $answer = json_decode($response->body(), true, 64, JSON_BIGINT_AS_STRING);

        return is_array($answer) ? $answer : null;
    }

    /**
     * @param  array<array-key, mixed>  $answer
     */
    private static function words(array $answer): string
    {
        $words = is_string($answer['Message'] ?? null) ? [$answer['Message']] : [];

        foreach (is_array($answer['ValidationErrors'] ?? null) ? $answer['ValidationErrors'] : [] as $error) {
            if (is_array($error) && is_string($error['Error'] ?? null)) {
                $words[] = $error['Error'];
            }
        }

        return mb_substr(implode(' ', $words), 0, 300);
    }

    /**
     * @param  array<array-key, mixed>  $data  `Invoice`, `Transaction` and `Amount`, as a payment's or a session's answer holds them
     */
    private static function payment(array $data): ?PaymentAnswer
    {
        $invoice = is_array($data['Invoice'] ?? null) ? $data['Invoice'] : [];
        $transaction = is_array($data['Transaction'] ?? null) ? $data['Transaction'] : [];
        $amount = is_array($data['Amount'] ?? null) ? $data['Amount'] : [];
        $error = is_array($transaction['Error'] ?? null) ? $transaction['Error'] : [];
        $card = is_array($transaction['Card'] ?? null) ? $transaction['Card'] : [];
        [$invoiceId, $invoiceStatus] = [self::text($invoice, 'Id'), self::text($invoice, 'Status')];
        [$paymentId, $status] = [self::text($transaction, 'PaymentId'), self::text($transaction, 'Status')];

        // Without these nothing can be said of the payment.
        if ($invoiceId === null || $invoiceStatus === null || $paymentId === null || $status === null) {
            return null;
        }

        return new PaymentAnswer(
            $invoiceId,
            $invoiceStatus,
            self::text($invoice, 'ExternalIdentifier'),
            $paymentId,
            $status,
            self::text($transaction, 'PaymentMethod'),
            self::text($transaction, 'TransactionDate'),
            self::text($error, 'Code'),
            self::text($error, 'Message'),
            self::text($card, 'Brand'),
            self::text($amount, 'BaseCurrency'),
            self::text($amount, 'ValueInBaseCurrency'),
            self::text($amount, 'DisplayCurrency'),
            self::text($amount, 'ValueInDisplayCurrency'),
            self::text($amount, 'PayCurrency'),
            self::text($amount, 'ValueInPayCurrency'),
        );
    }

    /**
     * The value as the text MyFatoorah sent, an empty one as none. A bare number is taken only when whole:
     * anything else would already have passed through a float.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function text(array $data, string $name): ?string
    {
        $value = $data[$name] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
