import type { PendingVisit } from '@inertiajs/core';
import { keepsBoxes } from '@/lib/visits';

/*
| What `useLeaveGuard` (components/SaveBar.tsx) decides, apart from React, so it can be tested
| (pricing.md §4.3.1, "Leaving with changed boxes"):
|
| - **before a visit**: let it go - a prefetch (Inertia fetching a page ahead, which replaces nothing),
|   one that keeps the boxes (`keepsBoxes`, or the page's own judgement), any visit while nothing is
|   changed or while the person has already chosen to leave - or hold it, and cancel it;
| - **leaving**: make the held visit again exactly as it was asked - its options and its callbacks
|   (onSuccess, onFinish, reset, preserveUrl, async…) - letting it through this once.
*/

/** What Inertia hands its `before` listeners: the visit, with the options and callbacks it was asked with. */
export type HeldVisit = PendingVisit & Record<string, unknown>;

type Visitor = (url: PendingVisit['url'], options: Record<string, unknown>) => void;

export type LeaveGuard = {
    /** True to let the visit go; false when it is held, and the caller cancels it. */
    before: (visit: HeldVisit) => boolean;
    /** Makes the held visit again, as it was asked. */
    leave: (visit: HeldVisit) => void;
    /** Whether a visit made by `leave` is under way: the window's unload asks nothing then. */
    leaving: () => boolean;
};

/** The fields Inertia adds to a visit of its own, which a new visit makes afresh. */
const OWN = ['id', 'url', 'cancelled', 'completed', 'interrupted'];

/**
 * What the guards of one page share: a page with several sets of boxes - a product's store cards, each
 * with Pricing's and Inventory's - asks **once** for a visit, the first guard with changes asking, and a
 * person who chose to leave is let through by all of them (the review of stage 5's screens step 5).
 */
export type GuardHub = { leaving: boolean; asking: unknown };

export function guardHub(): GuardHub {
    return { leaving: false, asking: null };
}

export function leaveGuard(options: {
    dirty: () => boolean;
    keeps: (visit: HeldVisit) => boolean;
    hold: (visit: HeldVisit) => void;
    visit: Visitor;
    /** Shared with the page's other guards; a guard of its own when none is given. */
    hub?: GuardHub;
}): LeaveGuard {
    const hub = options.hub ?? guardHub();

    return {
        before: (visit) => {
            if (visit.prefetch || hub.leaving || !options.dirty() || keepsBoxes(visit) || options.keeps(visit)) {
                return true;
            }

            // Another guard of the page is asking about this visit already: cancelled, not asked twice.
            if (hub.asking === visit.id && visit.id !== undefined) {
                return false;
            }

            hub.asking = visit.id;
            options.hold(visit);

            return false;
        },
        leave: (visit) => {
            const asked: Record<string, unknown> = {};

            for (const [key, value] of Object.entries(visit)) {
                if (!OWN.includes(key)) {
                    asked[key] = value;
                }
            }

            const finish = typeof visit.onFinish === 'function' ? (visit.onFinish as (...args: unknown[]) => unknown) : null;
            hub.leaving = true;
            hub.asking = null;
            options.visit(visit.url, {
                ...asked,
                onFinish: (...args: unknown[]) => {
                    hub.leaving = false;
                    finish?.(...args);
                },
            });
        },
        leaving: () => hub.leaving,
    };
}
