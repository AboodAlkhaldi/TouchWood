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
        appending.current = true;

        router.get(url, cursor, {
            only,
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
            // A page that never came adds nothing.
            onCancel: () => {
                appending.current = false;
            },
            onError: () => {
                appending.current = false;
            },
        });
    }

    return { rows, loading, more };
}
