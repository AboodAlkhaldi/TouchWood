/*
| Leaving a page with changed boxes (resources/js/lib/leave-guard.ts, behind components/SaveBar.tsx's
| useLeaveGuard; pricing.md §4.3.1): which visits are held and cancelled, and how a held one is made
| again. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { changedPlaces, guardHub, type HeldVisit, leaveGuard } from '@/lib/leave-guard';

/** A visit as Inertia hands its `before` listeners one: its own fields, the options and callbacks asked with. */
function visit(overrides: Record<string, unknown> = {}): HeldVisit {
    return {
        id: 'visit-1',
        cancelled: false,
        completed: false,
        interrupted: false,
        url: new URL('http://localhost/admin/prices?store=sa'),
        method: 'get',
        data: {},
        replace: false,
        preserveScroll: false,
        preserveState: false,
        only: [],
        except: [],
        headers: {},
        prefetch: false,
        reset: [],
        preserveUrl: false,
        async: false,
        ...overrides,
    } as unknown as HeldVisit;
}

function guardWith(dirty: boolean, keeps: (visit: HeldVisit) => boolean = () => false) {
    const held: HeldVisit[] = [];
    const made: Array<{ url: unknown; options: Record<string, unknown> }> = [];
    const guard = leaveGuard({ dirty: () => dirty, keeps, hold: (each) => held.push(each), visit: (url, options) => made.push({ url, options }) });

    return { guard, held, made };
}

describe('before a visit', () => {
    it('holds and cancels a visit that would replace the page while boxes are changed', () => {
        const { guard, held } = guardWith(true);
        const leaving = visit();

        assert.equal(guard.before(leaving), false);
        assert.deepEqual(held, [leaving]);
    });

    it('lets a visit go while nothing is changed, a prefetch, a save, a partial reload and what the page keeps', () => {
        assert.equal(guardWith(false).guard.before(visit()), true);

        const { guard, held } = guardWith(true, (each) => String(each.url).includes('keep=1'));

        assert.equal(guard.before(visit({ prefetch: true })), true);
        assert.equal(guard.before(visit({ method: 'post' })), true);
        assert.equal(guard.before(visit({ only: ['rows'], preserveState: true })), true);
        assert.equal(guard.before(visit({ url: new URL('http://localhost/admin/prices?keep=1') })), true);
        assert.deepEqual(held, []);
    });
});

describe('leaving', () => {
    it('makes the held visit again with its options and callbacks, and lets it through this once', () => {
        const { guard, made } = guardWith(true);
        const finished: string[] = [];
        const onSuccess = () => finished.push('success');
        const held = visit({ reset: ['rows'], preserveUrl: true, async: true, preserveState: true, onSuccess, onFinish: () => finished.push('finish') });

        guard.leave(held);

        assert.equal(made.length, 1);
        const [{ url, options }] = made as [{ url: unknown; options: Record<string, unknown> }];
        assert.equal(url, held.url);
        assert.equal(options.onSuccess, onSuccess);
        assert.deepEqual([options.reset, options.preserveUrl, options.async, options.preserveState, options.method], [['rows'], true, true, true, 'get']);
        // Inertia's own fields of the visit are made afresh, never passed back.
        assert.equal('id' in options || 'url' in options || 'cancelled' in options, false);

        // Under way: the replay itself is not held, nor the window's unload asked.
        assert.equal(guard.leaving(), true);
        assert.equal(guard.before(visit()), true);

        (options.onFinish as () => void)();
        assert.deepEqual(finished, ['finish']);
        assert.equal(guard.leaving(), false);
        assert.equal(guard.before(visit()), false);
    });
});

describe('a page with several sets of boxes', () => {
    it('asks once for a visit, the first guard with changes asking, and lets the chosen leaving through all of them', () => {
        const hub = guardHub();
        const held: string[] = [];
        const made: Array<Record<string, unknown>> = [];
        const guard = (name: string, dirty: boolean) => leaveGuard({ dirty: () => dirty, keeps: () => false, hold: () => held.push(name), visit: (_url, options) => made.push(options), hub });
        const pricing = guard('pricing', true);
        const inventory = guard('inventory', true);
        const clean = guard('clean', false);
        const leaving = visit({ id: 'visit-2' });

        assert.equal(pricing.before(leaving), false);
        assert.equal(inventory.before(leaving), false);
        assert.equal(clean.before(leaving), true);
        assert.deepEqual(held, ['pricing']);

        pricing.leave(leaving);

        assert.equal(inventory.before(visit({ id: 'visit-3' })), true);
        assert.equal(inventory.leaving(), true);

        (made[0]?.onFinish as () => void)();
        assert.equal(inventory.before(visit({ id: 'visit-4' })), false);
        assert.deepEqual(held, ['pricing', 'inventory']);
    });

    it('counts every guard with changes in the question, and none whose boxes are gone (stage 5 step 10)', () => {
        const hub = guardHub();
        const places: number[] = [];
        const dirty = { pricing: true, inventory: true, other: true, clean: false };
        const guard = (name: keyof typeof dirty) => leaveGuard({ dirty: () => dirty[name], keeps: () => false, hold: (_visit, count) => places.push(count), visit: () => undefined, hub });
        const pricing = guard('pricing');
        guard('inventory');
        const other = guard('other');
        guard('clean');

        assert.equal(changedPlaces(hub), 3);
        assert.equal(pricing.before(visit({ id: 'visit-5' })), false);

        // A card's boxes gone: off the count, and counted again once drawn again.
        const detach = other.attach();
        detach();
        dirty.inventory = false;

        assert.equal(pricing.before(visit({ id: 'visit-6' })), false);

        other.attach();

        assert.equal(pricing.before(visit({ id: 'visit-7' })), false);
        assert.deepEqual(places, [3, 1, 2]);
    });

    it('says at least the one asking, on a guard of its own', () => {
        const places: number[] = [];
        const guard = leaveGuard({ dirty: () => true, keeps: () => false, hold: (_visit, count) => places.push(count), visit: () => undefined });

        assert.equal(guard.before(visit()), false);
        assert.deepEqual(places, [1]);
    });
});
