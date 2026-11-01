<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Modules\Payments\Application\Gateway\GatewayAccess;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\OpenedSession;
use Modules\Payments\Application\Gateway\PaymentAnswer;
use Modules\Payments\Application\Gateway\SessionAnswer;
use Modules\Payments\Domain\Exception\GatewayKeyRefused;
use Modules\Payments\Domain\Exception\GatewayRefused;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Model\GatewayCall;
use Modules\Payments\Domain\Repository\GatewayCalls;

/**
 * MyFatoorah's API, v3 (payments.md §1.3; notes §0): a 30-second timeout, a status asked three times five
 * seconds apart (notes §0 #16), every try logged with the key never in it (rule 13). A 401 is the key's; any
 * other 4xx, or an answer saying it failed, is MyFatoorah's no; no answer, a 5xx, a 429 or an answer we cannot
 * read means we do not know, and is never taken for a no.
 */
final readonly class MyFatoorahClient implements GatewayClient
{
    private const int TIMEOUT_SECONDS = 30;

    private const int STATUS_TRIES = 3;

    private const int SECONDS_BETWEEN_TRIES = 5;

    /** The session always names them: one switched on later never appears by itself (§1.3). */
    private const array METHODS = ['card', 'applepay', 'stcpay'];

    private const array CARD_NETWORKS = ['visa', 'masterCard', 'mada'];

    public function __construct(
        private Http $http,
        private GatewayCalls $calls,
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

        $id = self::text($answer, 'SessionId');

        if ($id === null) {
            throw new GatewayUnreachable;
        }

        $expiry = self::text($answer, 'SessionExpiry');

        return new OpenedSession($id, $expiry === null ? $expiresAt : CarbonImmutable::parse($expiry)->toDateTimeImmutable());
    }

    public function paymentDetails(GatewayAccess $access, string $paymentId): PaymentAnswer
    {
        return self::payment($this->send($access, 'GET', '/v3/payments/'.rawurlencode($paymentId), null, self::STATUS_TRIES))
            ?? throw new GatewayUnreachable;
    }

    public function sessionDetails(GatewayAccess $access, string $sessionId): SessionAnswer
    {
        $answer = $this->send($access, 'GET', '/v3/sessions/'.rawurlencode($sessionId), null, self::STATUS_TRIES);

        if (! is_bool($answer['IsUsed'] ?? null)) {
            throw new GatewayUnreachable;
        }

        $result = $answer['TransactionResult'] ?? null;

        return new SessionAnswer($answer['IsUsed'], is_array($result) ? self::payment($result) : null);
    }

    public function registerApplePayDomain(GatewayAccess $access, string $domain): void
    {
        $this->send($access, 'POST', '/v2/RegisterApplePayDomain', ['DomainName' => $domain], 1);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<array-key, mixed> the answer's `Data`
     *
     * @throws GatewayKeyRefused|GatewayRefused|GatewayUnreachable
     */
    private function send(GatewayAccess $access, string $method, string $path, ?array $body, int $tries): array
    {
        for ($try = 1; ; $try++) {
            $response = $this->try($access, $method, $path, $body);

            if ($response !== null) {
                $status = $response->status();

                if ($status === 401) {
                    throw new GatewayKeyRefused;
                }

                $answer = $status >= 500 || $status === 429 ? null : self::decoded($response);

                if ($answer !== null) {
                    if ($status >= 400 || ($answer['IsSuccess'] ?? null) === false) {
                        throw new GatewayRefused(self::words($answer));
                    }

                    if ($status < 300) {
                        return is_array($answer['Data'] ?? null) ? $answer['Data'] : [];
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
     */
    private function try(GatewayAccess $access, string $method, string $path, ?array $body): ?Response
    {
        $started = hrtime(true);
        $endpoint = $method.' '.$path;
        $response = null;
        $error = null;

        try {
            $request = $this->http->withToken($access->key)->acceptJson()->timeout(self::TIMEOUT_SECONDS);
            $response = $method === 'GET' ? $request->get($access->apiBase.$path) : $request->post($access->apiBase.$path, $body ?? []);
        } catch (ConnectionException) {
            // Its message names the address and nothing of the key; ours names no more than it must.
            $error = 'No answer';
        }

        $this->calls->record(new GatewayCall(
            $access->storeId,
            $access->attemptId,
            $endpoint,
            $body === null ? null : MyFatoorahScrub::clean($body),
            $response?->status(),
            $response === null ? null : (is_array($answer = self::decoded($response)) ? MyFatoorahScrub::clean($answer) : null),
            $error,
            (int) ((hrtime(true) - $started) / 1_000_000),
        ));

        return $response;
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
