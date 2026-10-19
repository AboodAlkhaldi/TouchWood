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
 * the panel's own (frontend.md §2.6). The category-to-status table below exists nowhere else.
 */
final class ProblemDetails
{
    private const string CONTENT_TYPE = 'application/problem+json';

    /**
     * The statuses the admin panel answers a page request with in its own frame (frontend.md §2.6):
     * every category's (§7.1). Anything else - a session expired (419), too many requests, a bug -
     * keeps Laravel's page, which needs nothing the failing request may not have.
     */
    private const array PANEL_PAGE_STATUSES = [403, 404, 409, 413, 415, 422];

    /** The admin panel's error page, beside its Home and Coming Soon (resources/js/pages/Admin). */
    private const string PANEL_PAGE = 'Admin/Error';

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
            : self::panelPage(
                $request,
                $error->getStatusCode(),
                self::translate("errors.http.{$error->getStatusCode()}", [], (string) (Response::$statusTexts[$error->getStatusCode()] ?? 'Error')),
                self::pageSentence($error->getStatusCode()),
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
        $title = self::translate("{$key}.title", $replace, self::translate($categoryKey, [], (string) (Response::$statusTexts[$status] ?? 'Error')));

        // A refusal naming the rule it broke says that rule's words where the module wrote them (FormErrors).
        $rule = FormErrors::ruleKey($key, $error->context());

        // A page shows the translated title: Laravel's error pages print the exception's message,
        // and a DomainError's message is written for developers. In the panel, its own page says the
        // error's own sentence under it - "This read isn't available." - or, with none written, the
        // status's; never the message.
        if (! $request->expectsJson()) {
            $sentence = self::translate("{$key}.detail", $replace, self::pageSentence($status));

            return self::panelPage($request, $status, $title, $rule === null ? $sentence : self::translate($rule, $replace, $sentence))
                ?? app(ExceptionHandler::class)->render($request, new HttpException($status, $title, $error));
        }

        return self::problem(
            $error->type(),
            $status,
            $title,
            $rule === null
                ? self::translate("{$key}.detail", $replace, $error->getMessage())
                : self::translate($rule, $replace, self::translate("{$key}.detail", $replace, $error->getMessage())),
        );
    }

    /**
     * **The admin panel's error page** (frontend.md §2.6): in the panel's frame - its sidebar, its trail -
     * in the page's language, the error's translated title and one sentence, and a way back to Home.
     *
     * Only for a page asked for by somebody signed in to the panel: the frame names them and lists what
     * they may open, so a request that never reached the panel's own middleware - an address no route
     * answers, the sign-in pages - keeps Laravel's page. A form sent is answered in its own screen
     * (FormErrors) and never reaches here; anything not a plain read keeps Laravel's page too.
     */
    private static function panelPage(Request $request, int $status, string $title, string $sentence): ?Response
    {
        if (! in_array($status, self::PANEL_PAGE_STATUSES, true) || ! $request->isMethodSafe() || ! self::inSignedInPanel($request)) {
            return null;
        }

        return app(Page::class)
            ->render(self::PANEL_PAGE, ['status' => $status, 'title' => $title, 'detail' => $sentence], ['admin'])
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

    /** The status's own sentence, for an error page whose error wrote none (each of PANEL_PAGE_STATUSES has one). */
    private static function pageSentence(int $status): string
    {
        return self::translate("errors.page.{$status}", [], '');
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
