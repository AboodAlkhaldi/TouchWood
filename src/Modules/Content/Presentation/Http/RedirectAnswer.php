<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Modules\Content\Application\Redirects\RedirectLookup;
use Modules\Content\Domain\ValueObject\RedirectPath;
use Modules\Platform\Public\Contracts\UnmatchedAddress;

/**
 * Content's answer to an address nobody answers (content.md §1.15, §2.3 row L2): **an old site's address on a line
 * of the redirects answers 301 to its new page**, in one hop; any other stays not found. Platform asks it only where
 * the site would say "not found" - a path no route matched, a single word that names no store, a first word that
 * names none, an address under a store no page answers - so no shop page pays its query.
 *
 * **The Location is only ever the line's own new address**, on this site's root (`APP_URL`), each word
 * percent-encoded: never anything built from what was asked. The visit is counted with one small write **after the
 * answer is sent** (the application's terminating step), and nothing of the session is read or written.
 */
final readonly class RedirectAnswer implements UnmatchedAddress
{
    public function __construct(
        private RedirectLookup $lookup,
        private Config $config,
        private Application $app,
    ) {}

    public function answer(string $path): ?RedirectResponse
    {
        $line = $this->lookup->find($path);

        if ($line === null) {
            return null;
        }

        // Once: the application keeps its terminating steps, so a process that serves several requests (a test, a
        // long-lived server) would run this one again at each later request's end.
        $counted = false;
        $this->app->terminating(function () use (&$counted, $line): void {
            if (! $counted) {
                $counted = true;
                $this->lookup->counted($line['old']);
            }
        });

        return new RedirectResponse(rtrim((string) $this->config->get('app.url'), '/').RedirectPath::encoded($line['new']), 301);
    }
}
