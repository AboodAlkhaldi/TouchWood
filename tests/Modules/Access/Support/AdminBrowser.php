<?php

declare(strict_types=1);

namespace Tests\Modules\Access\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;

/**
 * One browser: it keeps the cookies each response sets (as a browser keeps them) and sends them back
 * encrypted, as a browser would, from its own IP address. Two of them are two separate browsers —
 * the test case's own cookie jar would share cookies between them.
 */
final class AdminBrowser
{
    /** @var array<string, string> name => decrypted value */
    private array $cookies = [];

    /**
     * @param  array<string, string>  $server  more of what the browser sends, such as HTTP_HOST
     */
    public function __construct(
        private readonly string $ip = '127.0.0.1',
        private readonly array $server = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->send('POST', $uri, $data);
    }

    /**
     * @return TestResponse<Response>
     */
    public function get(string $uri): TestResponse
    {
        return $this->send('GET', $uri);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function forget(string $name): void
    {
        unset($this->cookies[$name]);
    }

    /**
     * The form error a redirect carries, if any.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function formError(TestResponse $response): ?string
    {
        $errors = self::flashed($response, 'errors');

        if ($errors instanceof ViewErrorBag) {
            return $errors->first('form');
        }

        // Once saved, a JSON session holds the bag as an array (session.serialization = json).
        $message = is_array($errors) ? ($errors['default']['messages']['form'][0] ?? null) : null;

        return is_string($message) ? $message : null;
    }

    /**
     * A value the response's session carries — a flashed status, for one.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function flashed(TestResponse $response, string $key): mixed
    {
        $request = $response->baseRequest ?? throw new LogicException('The response has no request.');

        return $request->session()->get($key);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function send(string $method, string $uri, array $data = []): TestResponse
    {
        $key = app('encrypter')->getKey();
        $cookies = [];

        foreach ($this->cookies as $name => $value) {
            $cookies[$name] = encrypt(CookieValuePrefix::create($name, $key).$value, false);
        }

        $response = call($method, $uri, $data, $cookies, [], [...$this->server, 'REMOTE_ADDR' => $this->ip]);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);

                continue;
            }

            $value = $response->getCookie($cookie->getName())?->getValue();

            if (is_string($value)) {
                $this->cookies[$cookie->getName()] = $value;
            }
        }

        return $response;
    }
}
