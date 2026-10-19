import { useEffect, useRef, useState } from 'react';

/*
| A screen's reads as they are typed (pricing.md §4.3.1, "Loading"; Pricing's and Inventory's dialogs and
| pickers): JSON in the panel's session, read after a pause, the last answer kept while the next is on its
| way, a failure said rather than spun on forever, and an older answer never taking a newer one's place.
*/

/** A value that follows another after a pause - a search typed, a dialog's fields for its note. */
export function useDebounced<T>(value: T, delay = 300): T {
    const [settled, setSettled] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setSettled(value), delay);

        return () => clearTimeout(timer);
    }, [value, delay]);

    return settled;
}

/** A read the page asks as it is typed: JSON, in the panel's session; null when it is refused or fails. */
export async function readJson<T>(path: string, params: Record<string, string | string[] | boolean | null | undefined>, signal?: AbortSignal): Promise<T | null> {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            value.forEach((each) => query.append(`${key}[]`, each));
        } else if (typeof value === 'boolean') {
            if (value) {
                query.set(key, '1');
            }
        } else if (value !== null && value !== undefined && value !== '') {
            query.set(key, value);
        }
    }

    try {
        const response = await fetch(`${path}?${query.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal });

        return response.ok ? ((await response.json()) as T) : null;
    } catch {
        return null;
    }
}

/**
 * A read that follows what is typed (a dialog's note or preview, a picker's search): it keeps its last
 * answer while the next one is on its way (`loading`), and a later answer always wins over an earlier one.
 *
 * @param params  null while there is nothing to ask yet
 */
export function useLiveRead<T>(path: string, params: Record<string, string | string[] | boolean | null | undefined> | null, delay = 300) {
    const settled = useDebounced(params === null ? null : JSON.stringify(params), delay);
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    // Each read numbered: only the latest one's answer is taken, whatever order the answers arrive in.
    const latest = useRef(0);

    useEffect(() => {
        const mine = ++latest.current;

        if (settled === null) {
            setData(null);
            setLoading(false);
            setFailed(false);

            return;
        }

        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        readJson<T>(path, JSON.parse(settled) as Record<string, string | string[] | boolean | null>, controller.signal).then((answer) => {
            if (controller.signal.aborted || mine !== latest.current) {
                return;
            }

            setLoading(false);
            // Refused (403, 404, an invalid input) or failed: said, never a spinner forever or nothing at all.
            setFailed(answer === null);
            setData(answer);
        });

        return () => controller.abort();
    }, [path, settled, attempt]);

    return { data, loading, failed, retry: () => setAttempt((count) => count + 1) };
}
