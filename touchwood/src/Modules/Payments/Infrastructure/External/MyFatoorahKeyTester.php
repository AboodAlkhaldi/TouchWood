<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Domain\Exception\GatewayUnreachable;
use Modules\Payments\Domain\Exception\KeyPermissionMissing;
use Modules\Payments\Domain\Exception\KeyTestFailed;

/**
 * MyFatoorah's three test calls on a pasted key (payments.md §1.2), the v3 endpoints the first build step
 * used (§9.8): the methods list, a session (charges nothing), and a payment that does not exist. The key
 * travels only in the request's header and is never logged.
 */
final readonly class MyFatoorahKeyTester implements KeyTester
{
    private const int TIMEOUT_SECONDS = 15;

    /** MyFatoorah's answer to a key without an endpoint's permission (docs.myfatoorah.com/docs/api-key). */
    private const string NO_PERMISSION = 'does not have the required permissions';

    public function __construct(
        private Http $http,
    ) {}

    public function test(string $apiBase, #[\SensitiveParameter] string $key, string $currencyCode): void
    {
        $this->expect($this->send('GET', $apiBase.'/v3/payment-methods', $key), 'the payment methods', okOnly: true);
        $this->expect($this->send('POST', $apiBase.'/v3/sessions', $key, [
            'PaymentMode' => 'COLLECT_DETAILS',
            'Order' => ['Amount' => 1, 'Currency' => strtoupper($currencyCode)],
            'OperationType' => 'PAY',
        ]), 'the sessions', okOnly: true);
        // A payment that is not there: a "not found" says the key may read payments; a 401 that it may not.
        $this->expect($this->send('GET', $apiBase.'/v3/payments/0', $key), 'the payments', okOnly: false);
    }

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws GatewayUnreachable
     */
    private function send(string $method, string $url, #[\SensitiveParameter] string $key, ?array $body = null): Response
    {
        try {
            $request = $this->http->withToken($key)->acceptJson()->timeout(self::TIMEOUT_SECONDS);

            return $method === 'GET' ? $request->get($url) : $request->post($url, $body ?? []);
        } catch (ConnectionException) {
            throw new GatewayUnreachable;
        }
    }

    /**
     * @throws KeyPermissionMissing|KeyTestFailed|GatewayUnreachable
     */
    private function expect(Response $response, string $permission, bool $okOnly): void
    {
        // A 401 names a missing permission only in MyFatoorah's own words (notes §0 #18); any other 401 is
        // a key it does not take at all - wrong, expired, or of the other mode.
        if ($response->status() === 401) {
            throw str_contains($response->body(), self::NO_PERMISSION)
                ? new KeyPermissionMissing($permission)
                : new KeyTestFailed("{$permission} refused the key");
        }

        if ($response->serverError()) {
            throw new GatewayUnreachable;
        }

        if ($okOnly && ! $response->successful()) {
            throw new KeyTestFailed("{$permission} answered {$response->status()}");
        }
    }
}
