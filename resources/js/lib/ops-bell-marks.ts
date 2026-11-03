import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { readJson } from '@/lib/live-read';
import { ask, tell } from '@/lib/ops-bell';
import type { UnreadData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| What a bell asks the server (ops.md §4.15 O1, O2), apart from lib/ops-bell.ts since no page needs it on
| first paint. The marks are plain requests, not Inertia visits: they answer with the number, not a page.
*/

/** How long a mark may hold up going to a row's link: a failed mark still goes (O1). */
const MARK_MS = 4_000;

/** The number once more, coming back to the tab (Q7). A failed or signed-out answer changes nothing on screen. */
export function recheck(base: string): void {
    const order = ask();
    void readJson<UnreadData>(`${base}/unread`, {}).then((answer) => {
        if (answer !== null) {
            tell(order, { unread: answer.unread });
        }
    });
}

/** A mark's answer, the number after it; null when it failed. */
async function mark(path: string, token: string): Promise<number | null> {
    try {
        const response = await fetch(path, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(MARK_MS),
        });

        return response.ok ? ((await response.json()) as UnreadData).unread : null;
    } catch {
        return null;
    }
}

export async function markRow(base: string, id: string, token: string): Promise<void> {
    const order = ask();
    const unread = await mark(`${base}/${id}/read`, token);

    if (unread !== null) {
        tell(order, { unread, read: id });
    }
}

/**
 * Mark All as Read: every entry, not only the ones shown. It asks nothing, since it loses nothing.
 *
 * @param onDone  the button goes away with the last unread entry: focus is put somewhere first
 */
export function useMarkAll(base: string, onDone: () => void): { marking: boolean; failed: boolean; markAll: () => void; clear: () => void } {
    const { csrfToken } = usePage<SharedProps>().props;
    const [marking, setMarking] = useState(false);
    const [failed, setFailed] = useState(false);

    function markAll() {
        if (marking) {
            return;
        }

        const order = ask();
        setMarking(true);
        setFailed(false);
        void mark(`${base}/read-all`, csrfToken).then((unread) => {
            setMarking(false);
            setFailed(unread === null);

            if (unread !== null) {
                onDone();
                tell(order, { unread, all: true });
            }
        });
    }

    return { marking, failed, markAll, clear: () => setFailed(false) };
}
