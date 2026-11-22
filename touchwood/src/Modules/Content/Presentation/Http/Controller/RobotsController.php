<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Response;
use Modules\Content\Application\Sitemap\RobotsFile;

/**
 * `/robots.txt`, generated (content.md §1.14): each on store's lines in production, `Disallow: /` anywhere else.
 * `public/robots.txt` is gone, as a static file would answer before this route.
 */
final readonly class RobotsController
{
    public function __invoke(RobotsFile $robots, Application $app, Config $config): Response
    {
        return new Response($robots->text($app->environment('production'), rtrim((string) $config->get('app.url'), '/')), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
