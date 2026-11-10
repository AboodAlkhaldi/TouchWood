<?php

declare(strict_types=1);

namespace Tests\Modules\Content\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Modules\Content\Application\Redirects\AddressProbe;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Modules\Content\Infrastructure\External\HttpAddressProbe;

/**
 * Check Addresses' probe answered by this application itself, as the real one is answered over HTTP: the same GET,
 * through the same kernel, with no cookie - so a test sees what the shop really answers at each address. Only the
 * transport is stood in for (CONVENTIONS: replace the one thing, at the narrowest point).
 */
final class AppAddressProbe implements AddressProbe
{
    /** @var list<string> every path asked, in order */
    public array $asked = [];

    /**
     * @return array{status: int, location: string|null}
     */
    public function answer(string $path): array
    {
        $this->asked[] = $path;
        $root = rtrim((string) config('app.url'), '/');
        $request = Request::create($root.RedirectPath::encoded($path), 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
        $response = app(Kernel::class)->handle($request);
        $location = (string) $response->headers->get('Location');
        app()->forgetScopedInstances();

        return ['status' => $response->getStatusCode(), 'location' => $location === '' ? null : HttpAddressProbe::ownPath($location, $root)];
    }
}
