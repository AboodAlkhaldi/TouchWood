<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Modules\Platform\Application\Routing\InMemoryReservedPaths;
use Modules\Platform\Public\Contracts\UnmatchedAddress;
use Modules\Platform\Public\Contracts\UnmatchedAddresses;

/**
 * The modules' answers to an address nobody answers (content.md §2.3 row L2), collected from their service
 * providers. Asked from two places, both Platform's: `AnswerUnmatchedAddresses`, for a read no route matched,
 * and `StoreWithoutLanguageController`, for a single word that names no store.
 *
 * Kept in Presentation: an answer is an HTTP redirect, which Platform's Application layer never knows
 * (tests/Architecture/ErrorStandardTest).
 */
final class InMemoryUnmatchedAddresses implements UnmatchedAddresses
{
    /** @var list<class-string<UnmatchedAddress>> */
    private array $answers = [];

    public function __construct(
        private readonly Container $container,
        private readonly InMemoryReservedPaths $reserved,
    ) {}

    public function register(string $answer): void
    {
        if (! is_subclass_of($answer, UnmatchedAddress::class)) {
            throw new LogicException("\"{$answer}\" does not implement ".UnmatchedAddress::class.'.');
        }

        if (in_array($answer, $this->answers, true)) {
            throw new LogicException("\"{$answer}\" is registered twice.");
        }

        $this->answers[] = $answer;
    }

    /**
     * The first registered answer for the address, or null - with none registered, without asking anyone.
     * An address under a reserved first segment (`/admin`, `/build`, `/api`...) is never asked about, in any
     * case: it belongs to the module that reserved it.
     */
    public function answer(string $path): ?RedirectResponse
    {
        if ($this->answers === [] || $this->underReserved($path)) {
            return null;
        }

        foreach ($this->answers as $answer) {
            $resolved = $this->container->make($answer);
            $redirect = $resolved instanceof UnmatchedAddress ? $resolved->answer($path) : null;

            if ($redirect !== null) {
                return $redirect;
            }
        }

        return null;
    }

    /**
     * The address a request asked for, as an answer is given it: decoded, with its leading slash and any
     * trailing one, without the query string. Null for one that does not decode to UTF-8: no answer can
     * name it.
     */
    public static function pathOf(Request $request): ?string
    {
        $path = '/'.ltrim(rawurldecode($request->getPathInfo()), '/');

        return preg_match('//u', $path) === 1 ? $path : null;
    }

    private function underReserved(string $path): bool
    {
        $first = strtolower(explode('/', ltrim($path, '/'), 2)[0]);

        return $first !== '' && $this->reserved->isReserved($first);
    }
}
