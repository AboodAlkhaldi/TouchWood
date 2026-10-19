/*
| A moment typed in the store's clock (resources/js/lib/moments.ts; pricing.md §4.3.1 "Moments", Q4),
| a batch refusal's rows (lib/row-errors.ts, Q2) and the visits that keep typed boxes (lib/visits.ts,
| §4.3.1). Run by tests/Frontend/run.mjs, inside the PHP suite (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { problemOf } from '@/lib/checks';
import { hasPassed, instantOf, isoOf, MOMENT_WORDS, momentBox, momentFrom, type MomentInput, momentProblem, noMoment, offsetMinutes, wallClock } from '@/lib/moments';
import { firstMarked, rowErrors } from '@/lib/row-errors';
import { keepsBoxes } from '@/lib/visits';

const RIYADH = 'Asia/Riyadh';
const LONDON = 'Europe/London';

function typed(date: string, time: string, typedAt = 0): MomentInput {
    return { none: false, date, time, typedAt };
}

describe('a moment in a store\'s clock', () => {
    it('reads a zone\'s wall clock and its offset at an instant', () => {
        const at = Date.parse('2026-10-20T11:30:00Z');

        assert.deepEqual(wallClock(RIYADH, at), { date: '2026-10-20', time: '14:30' });
        assert.equal(offsetMinutes(RIYADH, at), 180);
        assert.equal(offsetMinutes('UTC', at), 0);
        // Midnight is 00, never 24.
        assert.deepEqual(wallClock('UTC', Date.parse('2026-10-20T00:05:00Z')), { date: '2026-10-20', time: '00:05' });
    });

    it('sends the moment as ISO 8601 with the store\'s offset on that day', () => {
        assert.equal(isoOf(typed('2026-10-20', '14:30'), RIYADH), '2026-10-20T14:30:00+03:00');
        // A zone whose clocks change: summer, then winter.
        assert.equal(isoOf(typed('2026-07-01', '09:00'), LONDON), '2026-07-01T09:00:00+01:00');
        assert.equal(isoOf(typed('2026-12-01', '09:00'), LONDON), '2026-12-01T09:00:00+00:00');
        assert.equal(isoOf(typed('2026-10-20', '14:30'), 'America/New_York'), '2026-10-20T14:30:00-04:00');
        assert.equal(Date.parse(isoOf(typed('2026-10-20', '14:30'), RIYADH) ?? ''), Date.parse('2026-10-20T11:30:00Z'));
    });

    it('sends nothing for Now or No End, nor for a date or time that is not one', () => {
        assert.equal(isoOf(noMoment(), RIYADH), null);
        assert.equal(isoOf(typed('2026-10-20', '24:00'), RIYADH), null);
        assert.equal(isoOf(typed('2026-02-31', '10:00'), RIYADH), null);
        assert.equal(isoOf(typed('2026-10-20', '9:30'), RIYADH), null);
        assert.equal(instantOf('', '10:00', RIYADH), null);
    });

    it('shows a moment the server sent in the store\'s clock', () => {
        const moment = momentFrom('2026-10-20T11:30:00+00:00', RIYADH);

        assert.deepEqual([moment.none, moment.date, moment.time], [false, '2026-10-20', '14:30']);
        assert.equal(momentFrom(null, RIYADH).none, true);
        assert.equal(momentFrom('not a moment', RIYADH).none, true);
    });
});

describe('what a moment says as it is typed', () => {
    const now = Date.parse('2026-10-20T11:30:20Z'); // 14:30:20 in Riyadh

    it('refuses a start before the minute it was typed in, and takes this minute', () => {
        assert.equal(momentProblem(typed('2026-10-20', '14:29', now), 'start', RIYADH, null, now), MOMENT_WORDS.notPast);
        assert.equal(momentProblem(typed('2026-10-19', '23:00', now), 'start', RIYADH, null, now), MOMENT_WORDS.notPast);
        assert.equal(momentProblem(typed('2026-10-20', '14:30', now), 'start', RIYADH, null, now), null);
        assert.equal(momentProblem(typed('2026-10-21', '09:00', now), 'start', RIYADH, null, now), null);
    });

    it('does not refuse a start that passed after it was typed: the server takes it as now, and the field says so', () => {
        const start = typed('2026-10-20', '14:35', now);
        const later = now + 10 * 60_000;

        assert.equal(momentProblem(start, 'start', RIYADH, null, later), null);
        assert.equal(hasPassed(start, RIYADH, later), true);
        assert.equal(hasPassed(start, RIYADH, now), false);
        assert.equal(hasPassed(noMoment(), RIYADH, later), false);
    });

    it('refuses an end not after its start, or not after now when the start is Now', () => {
        const start = typed('2026-10-21', '09:00', now);

        assert.equal(momentProblem(typed('2026-10-21', '09:00', now), 'end', RIYADH, start, now), MOMENT_WORDS.afterStart);
        assert.equal(momentProblem(typed('2026-10-20', '23:00', now), 'end', RIYADH, start, now), MOMENT_WORDS.afterStart);
        assert.equal(momentProblem(typed('2026-10-21', '09:01', now), 'end', RIYADH, start, now), null);
        assert.equal(momentProblem(typed('2026-10-20', '14:30', now), 'end', RIYADH, noMoment(), now), MOMENT_WORDS.afterStart);
        assert.equal(momentProblem(typed('2026-10-20', '14:31', now), 'end', RIYADH, noMoment(), now), null);
    });

    it('names a missing date and a time that is not one, and says nothing of Now, No End or an empty field', () => {
        assert.equal(momentProblem(typed('', '10:00', now), 'start', RIYADH, null, now), MOMENT_WORDS.date);
        assert.equal(momentProblem(typed('2026-10-21', '25:00', now), 'start', RIYADH, null, now), MOMENT_WORDS.time);
        assert.equal(momentProblem(typed('2026-10-21', '10', now), 'start', RIYADH, null, now), MOMENT_WORDS.time);
        assert.equal(momentProblem(noMoment(), 'start', RIYADH, null, now), null);
        assert.equal(momentProblem(typed('', '', now), 'end', RIYADH, null, now), null);
    });

    it('is one box of its form\'s checks: required unless Now or No End, its own rule in its own words', () => {
        const empty = momentBox(typed('', '', now), { id: 'sale-start', label: 'Start', kind: 'start', zone: RIYADH });
        const past = momentBox(typed('2020-01-01', '10:00', now), { id: 'sale-start', label: 'Start', kind: 'start', zone: RIYADH });
        const none = momentBox(noMoment(), { id: 'sale-start', label: 'Start', kind: 'start', zone: RIYADH });

        assert.equal(empty.id, 'sale-start-time');
        assert.equal(problemOf(empty.value, empty.rules)?.rule, 'required');
        assert.deepEqual(problemOf(past.value, past.rules), { rule: 'format', values: {}, key: MOMENT_WORDS.notPast });
        assert.equal(none.off, true);
    });
});

describe('a box\'s own rule (lib/checks.ts)', () => {
    it('is said by its own key once the value\'s shape passes', () => {
        assert.deepEqual(problemOf('abc', { own: () => 'module::words.rule' }), { rule: 'format', values: {}, key: 'module::words.rule' });
        assert.equal(problemOf('abc', { own: () => null }), null);
        // An empty box is still only "required".
        assert.equal(problemOf('', { required: true, own: () => 'never' })?.rule, 'required');
        assert.equal(problemOf('', { own: () => 'never' }), null);
    });
});

describe('a batch refusal\'s rows', () => {
    it('reads each marked size\'s message out of the page\'s errors, and nothing else', () => {
        const errors = { form: 'Nothing was saved.', 'rows.01k7aaaa': 'Price must be more than 0.', 'rows.01k7bbbb': 'Below the ceiling.', rows: 'not one', 'rows.': 'empty', price: 'A field' };

        assert.deepEqual(rowErrors(errors), { '01k7aaaa': 'Price must be more than 0.', '01k7bbbb': 'Below the ceiling.' });
        assert.deepEqual(rowErrors({}), {});
    });

    it('finds the first marked row in the page\'s order', () => {
        const rows = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

        assert.deepEqual(firstMarked(rows, (row) => row.id, { c: 'x', b: 'y' }), { id: 'b' });
        assert.equal(firstMarked(rows, (row) => row.id, {}), undefined);
    });
});

describe('the visits that keep typed boxes', () => {
    it('keeps them for a save and for a partial reload that keeps the page, never for another page', () => {
        assert.equal(keepsBoxes({ method: 'post', only: [], preserveState: false }), true);
        assert.equal(keepsBoxes({ method: 'get', only: ['rows', 'more'], preserveState: true }), true);
        assert.equal(keepsBoxes({ method: 'get', only: ['drawer'], preserveState: 'errors' }), true);
        assert.equal(keepsBoxes({ method: 'get', only: [], preserveState: true }), false);
        assert.equal(keepsBoxes({ method: 'get', only: ['rows'], preserveState: false }), false);
    });
});

describe('a saved moment and a clock that jumps', () => {
    const now = Date.parse('2026-10-20T11:30:20Z'); // 14:30:20 in Riyadh

    it('does not refuse a saved start that has passed while it is left as it was - a running offer\'s', () => {
        const running = momentFrom('2026-10-18T06:00:00+00:00', RIYADH);

        assert.equal(running.saved, '2026-10-18 09:00');
        assert.equal(momentProblem(running, 'start', RIYADH, null, now), null);
        assert.equal(hasPassed(running, RIYADH, now), false);
        // Moved, it is a typed start again, and a past one is refused.
        assert.equal(momentProblem({ ...running, time: '10:00', typedAt: now }, 'start', RIYADH, null, now), MOMENT_WORDS.notPast);
    });

    it('sends a time the clocks skip as the instant it checked, with the offset in force then', () => {
        // Cairo moves its clocks from 00:00 to 01:00 on 24 April 2026: 00:30 does not exist.
        const skipped = typed('2026-04-24', '00:30');
        const at = instantOf('2026-04-24', '00:30', 'Africa/Cairo');
        const iso = isoOf(skipped, 'Africa/Cairo');

        assert.equal(iso, '2026-04-24T01:30:00+03:00');
        assert.equal(Date.parse(iso ?? ''), at);
        // Either side of the change, the wall time as typed.
        assert.equal(isoOf(typed('2026-04-23', '23:30'), 'Africa/Cairo'), '2026-04-23T23:30:00+02:00');
        assert.equal(isoOf(typed('2026-04-24', '01:30'), 'Africa/Cairo'), '2026-04-24T01:30:00+03:00');
    });

    it('puts Save\'s focus on the date button while there is no day, else on the time box', () => {
        const field = { id: 'sale-start', label: 'Start', kind: 'start' as const, zone: RIYADH };

        assert.equal(momentBox(typed('', '10:00'), field).focus, 'sale-start-date');
        assert.equal(momentBox(typed('2026-10-21', '25:00'), field).focus, 'sale-start-time');
    });
});
