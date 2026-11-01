<?php

declare(strict_types=1);

namespace Tests\Modules\Payments\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A faked MyFatoorah in the shapes its test server gave (payments.md §9.8): it can be told to answer, time out,
 * answer the same key the same way, or report in progress. No test calls the real one.
 */
final class FakeMyFatoorah
{
    public const string PAYMENT_ID = '07077320290391998273';

    public const string INVOICE_ID = '7320290';

    public const string FRAME_URL = 'https://demo.myfatoorah.com/En/KWT/PayInvoice/MpgsAuthentication?paymentId=07077320290391998273';

    /** @var list<Closure(): PromiseInterface> what the next charge calls answer, then the invoice */
    public array $charges = [];

    /** @var list<array{string, string}> method and path of every call */
    public array $calls = [];

    /** @var list<string> idempotency keys seen on charges */
    public array $idempotencyKeys = [];

    /** Our attempt's id, as the last charge named it. */
    public ?string $attemptId = null;

    public ?string $invoiceId = self::INVOICE_ID;

    public string $status = 'INPROGRESS';

    public string $invoiceStatus = 'PENDING';

    public string $amount = '1180.50';

    public string $displayCurrency = 'SAR';

    public string $baseCurrency = 'SAR';

    public string $errorCode = '';

    /** Whether the session was charged, as `GET /v3/sessions/{id}` says. */
    public bool $sessionUsed = false;

    /** What status calls answer instead of the payment: none means they do. */
    public ?Closure $statusAnswer = null;

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request): PromiseInterface => $fake->answer($request));

        return $fake;
    }

    public function count(string $method, string $pathStart): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === $method && str_starts_with($call[1], $pathStart)));
    }

    public function paid(string $amount = '1180.50'): void
    {
        [$this->status, $this->invoiceStatus, $this->amount] = ['SUCCESS', 'PAID', $amount];
    }

    public function declined(string $code = 'MF003'): void
    {
        [$this->status, $this->invoiceStatus, $this->errorCode] = ['FAILED', 'PENDING', $code];
    }

    private function answer(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = [$request->method(), $path];

        if ($request->method() === 'POST' && $path === '/v3/sessions') {
            $expiry = $request->data()['SessionExpiry'] ?? null;

            return Http::response(MyFatoorahAnswers::session('KWT-'.Str::uuid(), is_string($expiry) ? $expiry : '2027-01-01T10:15:00Z'), 201);
        }

        if ($request->method() === 'POST' && $path === '/v3/payments') {
            return $this->charge($request);
        }

        if ($this->statusAnswer !== null) {
            return ($this->statusAnswer)();
        }

        if (str_starts_with($path, '/v3/sessions/')) {
            return Http::response(['IsSuccess' => true, 'Data' => $this->sessionUsed
                ? ['IsUsed' => true, 'TransactionResult' => $this->payment(self::PAYMENT_ID)]
                : ['IsUsed' => false, 'TransactionResult' => null]]);
        }

        return Http::response(['IsSuccess' => true, 'Data' => $this->payment(basename($path))]);
    }

    private function charge(Request $request): PromiseInterface
    {
        $data = $request->data();
        $order = is_array($data['Order'] ?? null) ? $data['Order'] : [];
        $this->attemptId = is_string($order['ExternalIdentifier'] ?? null) ? $order['ExternalIdentifier'] : null;
        $this->idempotencyKeys[] = (string) ($request->header('Idempotency-Key')[0] ?? '');

        if ($this->charges !== []) {
            return array_shift($this->charges)();
        }

        $this->sessionUsed = true;

        return Http::response(['IsSuccess' => true, 'Message' => '', 'ValidationErrors' => null, 'Data' => [
            'InvoiceId' => $this->invoiceId, 'PaymentId' => self::PAYMENT_ID, 'PaymentURL' => self::FRAME_URL, 'PaymentCompleted' => false, 'TransactionDetails' => null,
            'Card' => ['Number' => '446404xxxxxx0007', 'Brand' => 'Visa'],
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $paymentId): array
    {
        $data = MyFatoorahAnswers::paymentData($this->status, $this->invoiceStatus, $this->errorCode, $this->errorCode === '' ? '' : 'BLOCKED');
        $data['Invoice']['Id'] = $this->invoiceId;
        $data['Invoice']['ExternalIdentifier'] = $this->attemptId;
        $data['Transaction']['PaymentId'] = $paymentId;
        $data['Amount'] = ['BaseCurrency' => $this->baseCurrency, 'ValueInBaseCurrency' => $this->amount, 'DisplayCurrency' => $this->displayCurrency, 'ValueInDisplayCurrency' => $this->amount, 'PayCurrency' => $this->baseCurrency, 'ValueInPayCurrency' => $this->amount];

        return $data;
    }
}
