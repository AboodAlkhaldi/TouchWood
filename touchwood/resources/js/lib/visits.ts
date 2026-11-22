import type { PendingVisit } from '@inertiajs/core';

/*
| Which Inertia visits keep a page's typed boxes (pricing.md §4.3.1, "Leaving with changed boxes"): a
| request that sends something - the save itself - and a partial reload that keeps the page's state -
| Show More, the size drawer opening or closing. Every other visit replaces the page, so leaving with
| changed boxes asks first (components/SaveBar.tsx, `useLeaveGuard`).
*/

export function keepsBoxes(visit: Pick<PendingVisit, 'method' | 'only' | 'preserveState'>): boolean {
    return visit.method !== 'get' || (visit.only.length > 0 && visit.preserveState !== false);
}
