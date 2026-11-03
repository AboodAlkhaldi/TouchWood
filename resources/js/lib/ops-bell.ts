import { useEffect, useRef, useState } from 'react';

/*
| What a bell and its full page share on first paint (ops.md §4.15 O1, O2): the number, and telling whoever
| is listening what changed, since a bell and a full page may both be open. What they ask the server is in
| lib/ops-bell-marks.ts, loaded later: this sits on every page, 60 KB being the limit (frontend.md §5).
|
| Requests are numbered: the number on screen is the newest asked's, whatever order the answers arrive in,
| since sessions don't lock and two marks run side by side (the review of the bells).
*/

/** What changed: the number after it, if this answer is the newest, and which row, or every row shown. */
export type BellChange = { unread?: number; read?: string; all?: boolean };

const CHANGE = 'ops:bell-change';

let asked = 0;
let applied = 0;

/** A place in line, taken before a request goes out. */
export function ask(): number {
    return ++asked;
}

/** An older answer than one already shown still says which row was marked; its number is out of date. */
export function tell(order: number, change: BellChange): void {
    const late = order < applied;
    applied = Math.max(applied, order);
    window.dispatchEvent(new CustomEvent<BellChange>(CHANGE, { detail: late ? { ...change, unread: undefined } : change }));
}

export function useBellChanges(listener: (change: BellChange) => void): void {
    const latest = useRef(listener);
    latest.current = listener;

    useEffect(() => {
        const heard = (event: Event) => latest.current((event as CustomEvent<BellChange>).detail);
        window.addEventListener(CHANGE, heard);

        return () => window.removeEventListener(CHANGE, heard);
    }, []);
}

/**
 * The number on a bell: the server's with each full answer, then whatever a mark answered.
 *
 * Never written back into the page (router.replaceProp) so that going Back would show it: that stores the page's
 * props in its history entry, and after Show More those hold the last page alone, so Back brought back only that
 * page, on every list (found 2026-11-03). Back shows the number it left until the next page or the bell's opening.
 */
export function useBellNumber(fromServer: number): number {
    const [unread, setUnread] = useState(fromServer);

    useEffect(() => setUnread(fromServer), [fromServer]);
    useBellChanges((change) => {
        if (change.unread !== undefined) {
            setUnread(change.unread);
        }
    });

    return unread;
}
