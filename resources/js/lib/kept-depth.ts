import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

/*
| A list that keeps its depth through a change (the review of stage 5's screens step 5): a save, a refusal
| or a switch sends the page back from the server with its first page only - the rows shown with Show More
| would be gone, and a refusal's marks on them with them. So when the page sends something (any visit that
| is not a GET), the pages shown are remembered, and once the first page is back the next ones are asked
| for again, one after another, until the list is as deep as it was - or ends sooner.
|
| A GET that replaces the page - a filter, a search, another store - starts the list again at its top; a
| partial reload (Show More itself, the drawer) leaves it as it is.
*/

type List = { pages: number; loading: boolean };

/**
 * @param more  asks for the page after `next`, appended to the list
 * @return whether the list is still being brought back to its depth
 */
export function useKeptDepth(list: List, next: string | null, more: (cursor: string) => void): boolean {
    const wanted = useRef(1);
    // A change has gone out, and its first page hasn't replaced the list yet. The answer's `next` arrives a render
    // before the list's page count falls: without this, that render took the depth as reached and forgot it.
    const waiting = useRef(false);
    const shown = useRef(list.pages);
    shown.current = list.pages;

    useEffect(
        () =>
            router.on('start', (event) => {
                const visit = event.detail.visit;

                if (visit.method !== 'get') {
                    wanted.current = Math.max(wanted.current, shown.current);
                    waiting.current = true;
                } else if (visit.only.length === 0 && !visit.prefetch) {
                    wanted.current = 1;
                    waiting.current = false;
                }
            }),
        [],
    );

    const restoring = wanted.current > list.pages && next !== null;

    useEffect(() => {
        if (!restoring) {
            if (!waiting.current) {
                wanted.current = 1;
            }

            return;
        }

        waiting.current = false;

        if (!list.loading && next !== null) {
            more(next);
        }
        // Each page that arrives asks for the next one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [list.pages, list.loading, next, restoring]);

    return restoring;
}
