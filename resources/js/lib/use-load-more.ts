import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/*
| Geist's Load More Button: "a full-width button used to append more items to a paginated list"
| (owner, 2026-10-04: Show More adds the next page under the rows already shown).
|
| The server still answers one keyset page at a time; this keeps what was shown and adds the next
| page under it. The address stays on the first page (Inertia's preserveUrl), so a refresh starts
| the list again from the top rather than in the middle of it. Any other visit - a filter, an action
| that sent the page back - replaces the list with what the server sent, as before.
*/

export function useLoadMore<T>(items: T[], key: (item: T) => string) {
    const [rows, setRows] = useState(items);
    const [loading, setLoading] = useState(false);
    const appending = useRef(false);
    // Which "more" visit is the latest, and whether one is out: a double click sends one.
    const latest = useRef(0);
    const out = useRef(false);

    useEffect(() => {
        if (!appending.current) {
            setRows(items);

            return;
        }

        appending.current = false;
        setRows((before) => {
            const seen = new Set(before.map(key));

            return [...before, ...items.filter((item) => !seen.has(key(item)))];
        });
        // The key reads the item; only a new page of items should run this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items]);

    /** Fetches the next page into the list: the props it names are the ones that change. */
    function more(url: string, cursor: Record<string, string | number | null>, only: string[]) {
        if (out.current) {
            return;
        }

        const visit = ++latest.current;
        // A page that never came adds nothing - but only this visit's own end says so, never an
        // older one ending after a newer one began (the review of batch D).
        const gone = () => {
            if (latest.current === visit) {
                appending.current = false;
            }
        };

        out.current = true;
        appending.current = true;
        let arrived = false;

        router.get(url, cursor, {
            only,
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            onStart: () => setLoading(true),
            onSuccess: () => {
                arrived = true;
            },
            // However it ended - cancelled, refused, a server or network failure - a page that never
            // came leaves the list replaced by the next answer, not appended to (the final review).
            onFinish: () => {
                out.current = false;
                setLoading(false);

                if (!arrived) {
                    gone();
                }
            },
        });
    }

    return { rows, loading, more };
}
