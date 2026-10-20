<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Shared\Application\CorrelationId;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one place errors become HTTP responses (handoff §5.3, Platform spec §7).
 *
 * JSON requests get an RFC 7807 problem document with the same shape from every module.
 * Page requests get the error page for the matching status - inside the signed-in admin panel,
 * the panel's own, and in a store's shop, the shop's (frontend.md §2.6). The category-to-status
 * table below exists nowhere else.
 */
final class ProblemDetails
{
    private const string CONTENT_TYPE = 'application/problem+json';

    /**
     * The statuses the admin panel and the shop answer a page request with in their own frames
     * (frontend.md §2.6): every category's (§7.1). Anything else - a session expired (419), too many
     * requests, a bug - keeps Laravel's page, which needs nothing the failing request may not have.
     */
    private const array PAGE_STATUSES = [403, 404, 409, 413, 415, 422];

    /** The admin panel's error page, beside its Home and Coming Soon (resources/js/pages/Admin). */
    private const string PANEL_PAGE = 'Admin/Error';

    /** The shop's error page, in the shop's frame (resources/js/pages/Storefront). */
    private const string SHOP_PAGE = 'Storefront/Error';

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport(DomainError::class);

        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->expectsJson());

        $exceptions->render(fn (DomainError $error, Request $request): Response => self::renderDomainError($error, $request));

        // A ValidationException that already carries its own response keeps it.
        $exceptions->render(fn (ValidationException $error, Request $request): ?JsonResponse => $request->expectsJson() && $error->response === null
            ? self::problem(
                'validation_failed',
                $error->status,
                self::translate('errors.validation_failed.title', [], 'The given data was invalid.'),
                self::translate('errors.validation_failed.detail', [], $error->getMessage()),
                ['errors' => $error->errors()],
            )
            : null);

        $exceptions->render(fn (HttpExceptionInterface $error, Request $request): ?Response => $request->expectsJson()
            ? self::httpProblem($error->getStatusCode(), $error->getHeaders())
            : self::errorPage(
                $request,
                $error->getStatusCode(),
                self::translate("errors.http.{$error->getStatusCode()}", [], (string) (Response::$statusTexts[$error->getStatusCode()] ?? 'Error')),
                null,
            ));

        // Anything else reaching here is a bug. It has already been reported with the
        // correlation id; the caller learns nothing about the internals.
        $exceptions->render(function (Throwable $error, Request $request): ?JsonResponse {
            if (! $request->expectsJson()
                || $error instanceof DomainError
                || $error instanceof ValidationException
                || $error instanceof HttpExceptionInterface
                || $error instanceof HttpResponseException
                || $error instanceof AuthenticationException) {
                return null;
            }

            return self::httpProblem(500);
        });
    }

    public static function status(ErrorCategory $category): int
    {
        return match ($category) {
            ErrorCategory::NotFound => 404,
            ErrorCategory::Forbidden => 403,
            ErrorCategory::Conflict => 409,
            ErrorCategory::Invalid => 422,
            ErrorCategory::Unsupported => 415,
            ErrorCategory::TooLarge => 413,
        };
    }

    private static function renderDomainError(DomainError $error, Request $request): Response
    {
        $status = self::status($error->category());
        $key = self::translationKey($error->type());
        $replace = array_map(fn (string|int|float|bool|null $value): string => (string) $value, $error->context());
        $categoryKey = 'errors.category.'.strtolower($error->category()->value);
        $ownTitle = self::translate("{$key}.title", $replace, '');
        $title = $ownTitle !== '' ? $ownTitle : self::translate($categoryKey, [], (string) (Response::$statusTexts[$status] ?? 'Error'));

        // A page shows the translated title: Laravel's error pages print the exception's message,
        // and a DomainError's message is written for developers. In the panel and in the shop, their own
        // page says the error's own sentence under it, as a form says it (FormErrors: a rule's words, a
        // field named in words) - "This read isn't available." - or, with none written, the status's in
        // that area's words; never the message. A page refused on permission reads as a page, not an
        // action: "You don't have access to this page." (owner, 2026-10-20) - an action refused keeps its
        // own words, in its screen (FormErrors).
        if (! $request->expectsJson()) {
            $own = $error instanceof Unauthorized ? null : FormErrors::sentence($error);

            return self::errorPage($request, $status, $title, $own, titleIsOwn: $ownTitle !== '')
                ?? app(ExceptionHandler::class)->render($request, new HttpException($status, $title, $error));
        }

        // A refusal naming the rule it broke says that rule's words where the module wrote them (FormErrors).
        // With none written, the status's sentence, as the panel's page says it - never the developers'
        // message (owner, 2026-10-20).
        $rule = FormErrors::ruleKey($key, $error->context());
        $detail = self::translate("{$key}.detail", $replace, self::pageSentence($status));

        return self::problem(
            $error->type(),
            $status,
            $title,
            $rule === null ? $detail : self::translate($rule, $replace, $detail),
        );
    }

    /**
     * The error page of the area the request was answered in (frontend.md §2.6), or null for Laravel's.
     *
     * **The admin panel's**: in the panel's frame - its sidebar, its trail - in the page's language, the
     * error's translated title and one sentence, and a way back to Home. Only for a page asked for by
     * somebody signed in to the panel: the frame names them and lists what they may open, so a request
     * that never reached the panel's own middleware - the sign-in pages - keeps Laravel's page.
     *
     * **The shop's**: in the shop's frame - its header, Catalog's menu - in the page's language (the
     * address's), the error's own title or, for 409, 413, 415 and 422, a shopper's (owner, 2026-10-20), a
     * shopper's sentence ending with the fix, and a way back to the store's home; a page refused (403) says
     * the not-found page's title and sentence (owner, 2026-10-20). Only under
     * a store the address named and this request may see: an unknown store, or an off one to anybody but
     * a staff viewer, has no frame to draw and keeps Laravel's page (§1.6: nobody learns it exists).
     *
     * A form sent is answered in its own screen (FormErrors) and never reaches here; anything not a plain
     * read keeps Laravel's page too.
     *
     * @param  string|null  $own  the error's own sentence, or null to say its status's in the area's words
     * @param  bool  $titleIsOwn  whether $title is the error's own, rather than its status's or category's
     */
    private static function errorPage(Request $request, int $status, string $title, ?string $own, bool $titleIsOwn = false): ?Response
    {
        if (! in_array($status, self::PAGE_STATUSES, true) || ! $request->isMethodSafe()) {
            return null;
        }

        [$page, $heading, $sentence, $words] = match (true) {
            self::inSignedInPanel($request) => [self::PANEL_PAGE, $title, $own ?? self::pageSentence($status), ['admin']],
            // A page refused in the shop says what a missing one says, so the two can't be told apart - its
            // status still 403 (owner, 2026-10-20). The panel's says "You don't have access to this page."
            self::inShop($request) && $status === 403 => [self::SHOP_PAGE, self::translate('errors.http.404', [], 'Page Not Found'), self::shopSentence(404), [...StorefrontArea::WORDS, 'errors']],
            self::inShop($request) => [self::SHOP_PAGE, $titleIsOwn ? $title : self::shopTitle($status, $title), $own ?? self::shopSentence($status), [...StorefrontArea::WORDS, 'errors']],
            default => [null, '', '', []],
        };

        if ($page === null) {
            return null;
        }

        return app(Page::class)
            ->render($page, ['status' => $status, 'title' => $heading, 'detail' => $sentence], $words)
            ->toResponse($request)
            ->setStatusCode($status);
    }

    /**
     * Whether the request was answered inside the signed-in panel, so the frame's shared data - who is
     * signed in, the menu - is already there: its route passes the panel's page middleware and the sign-in
     * (AdminArea), and the menu was shared before it failed.
     */
    private static function inSignedInPanel(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        $middleware = $route->gatherMiddleware();

        return in_array(AdminArea::PAGE, $middleware, true)
            && in_array(AdminArea::SIGNED_IN, $middleware, true)
            && Inertia::getShared('menu') !== null;
    }

    /**
     * Whether the request was answered inside a store's shop, so the frame's shared data - the store and
     * its language (Platform's half), whoever is here (Access's) - is already there: its route passes the
     * shop's store, shop and page middleware (StorefrontArea), the address named a store this request may
     * see, and both halves were shared before it failed.
     */
    private static function inShop(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route || $request->attributes->get(StorefrontArea::RESOLVED_STORE) === null) {
            return false;
        }

        $middleware = $route->gatherMiddleware();

        return in_array(StorefrontArea::STORE, $middleware, true)
            && in_array(StorefrontArea::SHOP, $middleware, true)
            && in_array(StorefrontArea::PAGE, $middleware, true)
            && Inertia::getShared('shop') !== null
            && Inertia::getShared('shopper') !== null;
    }

    /**
     * The status's own sentence, for an error that wrote none - on the panel's page, and as a JSON
     * problem's detail. Each of PAGE_STATUSES has one, and so every category's status (§7.1).
     */
    private static function pageSentence(int $status): string
    {
        return self::translate("errors.page.{$status}", [], '');
    }

    /**
     * The same on the shop's page, in a shopper's words rather than the panel's - "This page isn't
     * available." (owner, 2026-10-20). Each of PAGE_STATUSES has one.
     */
    private static function shopSentence(int $status): string
    {
        return self::translate("errors.shop.sentence.{$status}", [], self::pageSentence($status));
    }

    /**
     * The shop's heading for an error that wrote no title of its own: a shopper's for 409, 413, 415 and 422 -
     * "Something Changed", not the panel's "Conflict" (owner, 2026-10-20) - else the status's, as the panel's.
     */
    private static function shopTitle(int $status, string $fallback): string
    {
        return self::translate("errors.shop.title.{$status}", [], $fallback);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function httpProblem(int $status, array $headers = []): JsonResponse
    {
        $fallback = (string) (Response::$statusTexts[$status] ?? 'Error');
        $title = self::translate("errors.http.{$status}", [], $fallback);

        return self::problem("http.{$status}", $status, $title, $title, [], $headers);
    }

    /**
     * "platform.store_code_taken" → "platform::errors.store_code_taken"; errors owned by the
     * Shared kernel use the application's own translation files.
     */
    private static function translationKey(string $type): string
    {
        [$namespace, $name] = str_contains($type, '.') ? explode('.', $type, 2) : ['shared', $type];

        return $namespace === 'shared' ? "errors.{$name}" : "{$namespace}::errors.{$name}";
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function translate(string $key, array $replace, string $fallback): string
    {
        $translated = trans($key, $replace);

        return is_string($translated) && $translated !== $key ? $translated : $fallback;
    }

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    private static function problem(string $type, int $status, string $title, string $detail, array $extensions = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'correlation_id' => Context::get(CorrelationId::CONTEXT_KEY),
                ...$extensions,
            ],
            $status,
            [...$headers, 'Content-Type' => self::CONTENT_TYPE],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
