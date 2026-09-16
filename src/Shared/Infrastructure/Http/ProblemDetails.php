<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
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
 * Page requests get the error page for the matching status. The category-to-status table
 * below exists nowhere else.
 */
final class ProblemDetails
{
    private const string CONTENT_TYPE = 'application/problem+json';

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

        $exceptions->render(fn (HttpExceptionInterface $error, Request $request): ?JsonResponse => $request->expectsJson()
            ? self::httpProblem($error->getStatusCode(), $error->getHeaders())
            : null);

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

        if (! $request->expectsJson()) {
            return app(ExceptionHandler::class)->render($request, new HttpException($status, $error->getMessage(), $error));
        }

        $key = self::translationKey($error->type());
        $replace = array_map(fn (string|int|float|bool|null $value): string => (string) $value, $error->context());
        $categoryKey = 'errors.category.'.strtolower($error->category()->value);

        return self::problem(
            $error->type(),
            $status,
            self::translate("{$key}.title", $replace, self::translate($categoryKey, [], (string) (Response::$statusTexts[$status] ?? 'Error'))),
            self::translate("{$key}.detail", $replace, $error->getMessage()),
        );
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
                'correlation_id' => Context::get(AssignCorrelationId::CONTEXT_KEY),
                ...$extensions,
            ],
            $status,
            [...$headers, 'Content-Type' => self::CONTENT_TYPE],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
