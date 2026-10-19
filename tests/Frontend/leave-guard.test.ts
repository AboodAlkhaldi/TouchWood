/*
| Leaving a page with changed boxes (resources/js/lib/leave-guard.ts, behind components/SaveBar.tsx's
| useLeaveGuard; pricing.md §4.3.1): which visits are held and cancelled, and how a held one is made
| again. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { type HeldVisit, leaveGuard } from '@/lib/leave-guard';

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
