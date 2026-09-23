<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Contracts\Foundation\Application;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders a page with the words it needs (frontend.md §1.5, §1.6).
 *
 * A screen names the translation files it uses and gets those, in the page's language, and nothing
 * else: the system's whole dictionary never travels to a browser. Naming them at the call site is
 * deliberate - a page that quietly inherited every file would grow its payload without anyone
 * noticing which screen did it.
 *
 * Framework glue, so it knows about Inertia and the translator and about no module.
 */
final readonly class Page
{
    public function __construct(
        private Translations $translations,
        private Application $app,
    ) {}

    /**
     * @param  array<string, mixed>  $props  the page's own data
     * @param  list<string>  $needs  the translation files this screen reads, e.g. ['access::auth']
     */
    public function render(string $component, array $props, array $needs): Response
    {
        return Inertia::render($component, [
            ...$props,
            'translations' => $this->translations->of($needs, $this->app->getLocale()),
        ]);
    }
}
