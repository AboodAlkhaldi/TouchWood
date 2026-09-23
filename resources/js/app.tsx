import { createInertiaApp, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { createRoot, hydrateRoot } from 'react-dom/client';
import '../css/app.css';

/*
| The browser half of every page (frontend.md §1.3).
|
| Usually the page arrives already rendered by the server, and this hydrates it: the person sees the
| screen before any of this runs.
|
| But the renderer is a separate Node process, and it can be down - during a deploy, on a machine
| where nobody started it, or when a page throws while rendering there. Then the server sends the
| shell with an **empty** root, and there is nothing to hydrate. hydrateRoot on an empty root does
| not "fill it": React fails with hydration error 418 and the person is left looking at a white page,
| which is exactly what the fallback exists to prevent (decided 2026-09-19).
|
| So the root decides: markup from the server is hydrated, an empty one is rendered from scratch.
*/

/**
 * The proof that a request came from one of our own pages.
 *
 * Kept here, from the page the server last sent, and put on every visit as `X-CSRF-TOKEN`. Laravel
 * looks for `_token`, then this header, then the XSRF-TOKEN cookie - and the cookie is the one thing
 * we cannot trust, because the admin panel and the storefront each write one under that name, on
 * different paths, and which of the two a browser sends first is its own business.
 *
 * It is read again from every answer because signing in regenerates the session, and the token with
 * it; a token remembered from before the sign-in would be refused straight afterwards.
 */
let csrfToken = '';

function rememberToken(page: Page | undefined): void {
    const token = (page?.props as { csrfToken?: unknown } | undefined)?.csrfToken;

    if (typeof token === 'string' && token !== '') {
        csrfToken = token;
    }
}

router.on('before', (event) => {
    if (csrfToken !== '') {
        event.detail.visit.headers['X-CSRF-TOKEN'] = csrfToken;
    }
});

router.on('success', (event) => rememberToken(event.detail.page));

void createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(`No page component for "${name}".`);
        }

        return page as never;
    },
    setup({ el, App, props }) {
        rememberToken(props.initialPage);

        const rendered = <App {...props} />;

        if (el.hasChildNodes()) {
            hydrateRoot(el, rendered);

            return;
        }

        createRoot(el).render(rendered);
    },
    progress: {
        // The brand colour, read from the theme rather than written here, so a campaign theme moves
        // the loading bar with everything else.
        color: getComputedStyle(document.documentElement).getPropertyValue('--tw-brand').trim(),
    },
});
