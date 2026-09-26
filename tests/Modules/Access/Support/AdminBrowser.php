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
     * A cookie this browser already carries — one another part of the site set, such as the guest
     * id Sales writes with the first cart line.
     */
    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
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
        // One process serves one request in production, so each request starts with a fresh session
        // store. In a test the application is reused, and without this two browsers would share one
        // store — and one signing out would sign the other out too. Everything that keeps a store
        // gets the new one, or a redirect would flash its errors into the old one.
        app('session')->forgetDrivers();
        $store = app('session')->driver();
        app()->instance('session.store', $store);
        app('redirect')->setSession($store);

        $key = app('encrypter')->getKey();
        $cookies = [];

        foreach ($this->cookies as $name => $value) {
            $cookies[$name] = encrypt(CookieValuePrefix::create($name, $key).$value, false);
        }

        $response = call($method, $uri, $data, $cookies, [], [...$this->server, 'REMOTE_ADDR' => $this->ip]);

        /*
        | This jar has **one slot per name**, where a browser keys a cookie by its name *and* its
        | path. That is usually close enough, and it stops being close enough the moment one
        | response both sets a cookie for the whole site and clears the same name under a path -
        | which is exactly what changing a preference does, to take away the /admin copy an older
        | build left behind (PreferencesController, 2026-09-26). Taking the clearing literally
        | would throw away the value just written.
        |
        | So a name being set in this response is never also cleared by it. Modelling paths
        | properly would be the fuller answer, and nothing here has needed it yet.
        */
        $set = [];

        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $set[$cookie->getName()] = true;
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                if (! isset($set[$cookie->getName()])) {
                    unset($this->cookies[$cookie->getName()]);
                }

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
