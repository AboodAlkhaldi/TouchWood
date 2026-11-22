/*
| Inventory's boxes checked as they are typed (inventory.md §4.3.1 "A number box"): whole numbers in their
| ranges, a quantity no more than what is in stock when removing, a correction's note. Pure functions, no
| React, so tests/Frontend/inventory-checks.test.ts runs them. They help; the server decides.
*/

const WHOLE = /^\d+$/;

/** The most a count may be: PostgreSQL's integer. */
export const COUNT_MOST = 2_147_483_647;

/** The most a threshold may be (§1.2, owner 2026-10-10). */
export const THRESHOLD_MOST = 100_000;

/** The longest note (§1.5). */
export const NOTE_MOST = 500;

/** A problem as its sentence's key under `check.` and its values. */
export type Problem = { key: string; values?: Record<string, number> };

/** A whole number typed, or null when it is not one. */
export function wholeOf(text: string): number | null {
    const value = text.trim();

    return WHOLE.test(value) ? Number(value) : null;
}

/** A count: a whole number from 0 to 2,147,483,647. */
export function countProblem(text: string): Problem | null {
    const count = wholeOf(text);

    return count === null || count > COUNT_MOST ? { key: 'count' } : null;
}

/**
 * A quantity added or removed: 1 or more - and, removing, no more than what is in stock.
 *
 * @param inStock  what is in stock, when removing
 */
export function quantityProblem(text: string, inStock: number | null = null): Problem | null {
    const quantity = wholeOf(text);

    if (quantity === null || quantity < 1 || quantity > COUNT_MOST) {
        return { key: 'quantity' };
    }

    return inStock !== null && quantity > inStock ? { key: 'quantity_max', values: { max: inStock } } : null;
}

/** A threshold: empty for the store's default, else a whole number from 0 to 100,000. */
export function thresholdProblem(text: string): Problem | null {
    if (text.trim() === '') {
        return null;
    }

    const threshold = wholeOf(text);

    return threshold === null || threshold > THRESHOLD_MOST ? { key: 'threshold' } : null;
}

/** A note: required, not blank, for a correction; at most 500 characters. */
export function noteProblem(reason: string, note: string): Problem | null {
    if ([...note].length > NOTE_MOST) {
        return { key: 'note_long' };
    }

    return reason === 'CORRECTION' && note.trim() === '' ? { key: 'note_required' } : null;
}

/** The reasons a direction offers (§1.5, owner 2026-10-11): Received only adds, Damaged or Lost and Offline Sale only remove. */
export function reasonsFor(direction: 'ADD' | 'REMOVE'): string[] {
    return direction === 'ADD' ? ['RECEIVED', 'CORRECTION'] : ['DAMAGED_LOST', 'OFFLINE_SALE', 'CORRECTION'];
}

/** What a count or a change leaves in stock against what orders hold: short when it leaves fewer than held. */
export function leavesShort(after: number, held: number): boolean {
    return held > 0 && after < held;
}
