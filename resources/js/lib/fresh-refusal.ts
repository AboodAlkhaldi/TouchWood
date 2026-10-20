/*
| The arithmetic behind `useFreshRefusal` (components/FormError.tsx): a dialog or a form says only a refusal
| that arrived while it was open. Finished visits are counted; the count when it opened is its mark; a
| refusal is its own once a visit has finished since. Run by tests/Frontend/fresh-refusal.test.ts.
|
| The mark is taken **on the render it opens**, not in an effect after it: read from an effect, the first
| render of a dialog opened again still held the last opening's mark, and said a refusal from before -
| another dialog's, or its own from last time - for one render (the follow-ups the owner accepted, 2026-10-19).
*/

/** When it opened, in finished visits; null while it is closed. */
export type OpenedAt = number | null;

/** The mark for this render: taken now on the render it opens, kept while it stays open, dropped as it closes. */
export function openedAtNow(previous: OpenedAt, open: boolean, visits: number): OpenedAt {
    if (!open) {
        return null;
    }

    return previous ?? visits;
}

/** The refusal to say: the page's, once a visit has finished since the opening; none while it is closed. */
export function freshRefusal(openedAt: OpenedAt, visits: number, message: string | undefined): string | undefined {
    return openedAt !== null && visits > openedAt && message !== undefined && message !== '' ? message : undefined;
}
