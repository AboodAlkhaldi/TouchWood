import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';

/*
| Geist's Load More Button: "a full-width button used to append more items to a paginated list"
| (owner, 2026-10-04: Show More adds the next page under the rows already shown).
|
| The server still answers one keyset page at a time; this keeps what was shown and adds the next
| page under it. The address stays on the first page (Inertia's preserveUrl), so a refresh starts
| the list again from the top rather than in the middle of it. Any other visit - a filter, an action
| that sent the page back - replaces the list with what the server sent, as before.
|
| **Going Back brings every row back, and the place on the page, with no request (owner, 2026-11-03).**
| A Show More visit writes nothing to the browser's history, so on its own Back would show the first page.
| So the rows shown are kept in the page's history entry, once the visit has ended, when history is written
| again. Inertia then stores the page as it stands, holding the last page: the next Show More goes on from
| there. The rows are kept under that page's first row, which is how a list knows them as its own when it comes
| back (React's useId changes with each mounting); a first page, or a tab's list opened later, finds nothing under
| its own first row and starts as the server sent.
*/

/** Where the lists' rows are kept in a history entry. One key, or a second list's write would drop the first's. */
const KEPT = 'load-more';
/** About 40 Show Mores. Past it nothing more is written, so Back returns to here: a browser limits a history entry. */
const KEPT_ROWS_AT_MOST = 2_000;

type Kept<T> = { rows: T[]; pages: number; beside?: unknown };

/**
 * This address's lists: Inertia answers a key an entry lacks from the first entry Back landed on in this document,
 * which may be another page's, sharing row ids with this one.
 */
function keptLists(url: string): Record<string, Kept<unknown>> {
    const found = router.restore(KEPT) as { url: string; lists: Record<string, Kept<unknown>> } | undefined;

    return found?.url === url ? found.lists : {};
}

/** What a list kept beside its rows (`useLoadMore`'s `beside`), for the page starting with this row. */
export function keptBeside(url: string, firstRow: string | undefined): unknown {
    return firstRow === undefined ? undefined : keptLists(url)[firstRow]?.beside;
}

/**
 * @param beside  what arrives page by page next to the rows and must come back with them: a shop list's hearts
 */
export function useLoadMore<T>(items: T[], key: (item: T) => string, beside?: unknown) {
    const { url } = usePage();
    const lastPage = items[0] === undefined ? '' : key(items[0]);
    const [kept] = useState(() => keptLists(url)[lastPage] as Kept<T> | undefined);
    const [rows, setRows] = useState(kept?.rows ?? items);
    // How many pages the list shows: a page that keeps its depth after a change asks for them again
    // (lib/kept-depth.ts).
    const [pages, setPages] = useState(kept?.pages ?? 1);
    const [loading, setLoading] = useState(false);
    // How many "more" visits ended without their page (lib/kept-depth.ts stops asking on one).
    const [failed, setFailed] = useState(0);
    const appending = useRef(false);
    // Which "more" visit is the latest, and whether one is out: a double click sends one.
    const latest = useRef(0);
    const out = useRef(false);
    const opening = useRef(true);
    // What the history entry holds for this list, and under which row.
    const saved = useRef<T[] | undefined>(kept?.rows);
    const savedAs = useRef(lastPage);
    const savedBeside = useRef(kept?.beside);
    // The place as this entry holds it on opening: on a short first page the browser cuts it down, and that is saved.
    const openedAt = useRef(typeof window === 'undefined' ? undefined : (window.history.state as { documentScrollPosition?: { top: number; left: number } } | null)?.documentScrollPosition);

    useEffect(() => {
        // A first page is written only to take deeper rows kept before it away.
        if (loading || (saved.current === rows && savedBeside.current === beside) || rows.length > KEPT_ROWS_AT_MOST || (pages === 1 && saved.current === undefined)) {
            return;
        }

        const { [savedAs.current]: replaced, ...others } = keptLists(url);
        saved.current = pages === 1 ? undefined : rows;
        savedAs.current = lastPage;
        savedBeside.current = beside;
        router.remember({ url, lists: pages === 1 ? others : { ...others, [lastPage]: { rows, pages, beside } } }, KEPT);
        // Rows and pages move together with the last page; only a list at rest is written.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, pages, loading, beside]);

    const goBackTo = useRef<typeof openedAt.current>(undefined);

    // Once the rows brought back are drawn, the page is long enough to reach the place.
    useEffect(() => {
        if (goBackTo.current !== undefined) {
            window.scrollTo(goBackTo.current.left, goBackTo.current.top);
            goBackTo.current = undefined;
        }
    }, [rows]);

    useEffect(() => {
        // The first answer is what the list opened with, which may be rows brought back.
        if (opening.current) {
            opening.current = false;

            return;
        }

        if (!appending.current) {
            // Back from a whole page load, from another site: the server's first page was drawn, and the entry
            // arrives now, on the same mounted list, too late for Inertia's own scrolling to find the rows.
            const found = keptLists(url)[lastPage] as Kept<T> | undefined;

            if (found !== undefined) {
                saved.current = found.rows;
                savedAs.current = lastPage;
                savedBeside.current = found.beside;
                goBackTo.current = openedAt.current;
            }

            setRows(found?.rows ?? items);
            setPages(found?.pages ?? 1);

            return;
        }

        appending.current = false;
        setPages((before) => before + 1);
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
            // A page of rows says nothing about a refusal on screen: asked for right after one, it would wipe its words.
            preserveErrors: true,
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
                    setFailed((before) => before + 1);
                }
            },
        });
    }

    return { rows, pages, loading, failed, more };
}
