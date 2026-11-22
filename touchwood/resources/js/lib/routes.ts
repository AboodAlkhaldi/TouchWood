/*
| Links to named routes (frontend.md §1.4).
|
| Ziggy's route list arrives with the page rather than through Blade's @routes directive, because
| the SSR renderer never runs Blade. Routes are sent **per area**: an admin page carries only the
| admin group, a storefront page only the storefront group, so a shopper's page never contains the
| admin URLs.
|
| Hiding a URL is not protection and nothing here relies on it: every admin route still checks the
| person's permission in its own handler (handoff §19).
*/

import { usePage } from '@inertiajs/react';
import { route as ziggy } from 'ziggy-js';
import type { SharedProps } from '@/types/page';

type Parameters = Record<string, string | number> | string | number | undefined;

/**
 * The URL of a named route, e.g. link('access.staff.sign-in').
 *
 * A name the page was not sent throws, rather than producing a broken link that only fails when
 * somebody clicks it.
 */
export function useLink(): (name: string, parameters?: Parameters) => string {
    const { routes } = usePage<SharedProps>().props;

    return (name, parameters) =>
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- Ziggy's own config shape.
        ziggy(name, parameters as never, undefined, routes as any);
}
