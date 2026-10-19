/*
| Inventory's boxes as they are typed (resources/js/pages/Inventory/Admin/checks.ts; inventory.md §4.3.1 "A
| number box"): a count, a quantity added or removed, a threshold, a correction's note, the reasons each
| direction offers, and when a change leaves orders short. Run by tests/Frontend/run.mjs, inside the PHP suite
| (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { countProblem, leavesShort, noteProblem, quantityProblem, reasonsFor, thresholdProblem, wholeOf } from '@/pages/Inventory/Admin/checks';

describe('a number as typed', () => {
    it('is a whole number, or nothing', () => {
        assert.equal(wholeOf(' 12 '), 12);
        assert.equal(wholeOf('0'), 0);
        assert.equal(wholeOf('1.5'), null);
        assert.equal(wholeOf('-3'), null);
        assert.equal(wholeOf('١٢'), null);
        assert.equal(wholeOf(''), null);
    });
});

describe('a count', () => {
    it('is a whole number from 0 to PostgreSQL\'s integer', () => {
        assert.equal(countProblem('0'), null);
        assert.equal(countProblem('2147483647'), null);
        assert.deepEqual(countProblem('2147483648'), { key: 'count' });
        assert.deepEqual(countProblem(''), { key: 'count' });
        assert.deepEqual(countProblem('abc'), { key: 'count' });
    });
});

describe('a quantity added or removed', () => {
    it('is 1 or more, and, removing, no more than is in stock', () => {
        assert.equal(quantityProblem('4'), null);
        assert.deepEqual(quantityProblem('0'), { key: 'quantity' });
        assert.equal(quantityProblem('6', 6), null);
        assert.deepEqual(quantityProblem('7', 6), { key: 'quantity_max', values: { max: 6 } });
    });
});

describe('a threshold', () => {
    it('is empty for the store\'s default, or a whole number to 100,000', () => {
        assert.equal(thresholdProblem(''), null);
        assert.equal(thresholdProblem('0'), null);
        assert.equal(thresholdProblem('100000'), null);
        assert.deepEqual(thresholdProblem('100001'), { key: 'threshold' });
        assert.deepEqual(thresholdProblem('2.5'), { key: 'threshold' });
    });
});

describe('a note', () => {
    it('is required for a correction, and at most 500 characters', () => {
        assert.equal(noteProblem('RECEIVED', ''), null);
        assert.deepEqual(noteProblem('CORRECTION', '   '), { key: 'note_required' });
        assert.equal(noteProblem('CORRECTION', 'Counted twice'), null);
        assert.equal(noteProblem('RECEIVED', 'x'.repeat(500)), null);
        assert.deepEqual(noteProblem('RECEIVED', 'x'.repeat(501)), { key: 'note_long' });
    });
});

describe('the reasons and the warning', () => {
    it('offers Received only to add, Damaged or Lost and Offline Sale only to remove', () => {
        assert.deepEqual(reasonsFor('ADD'), ['RECEIVED', 'CORRECTION']);
        assert.deepEqual(reasonsFor('REMOVE'), ['DAMAGED_LOST', 'OFFLINE_SALE', 'CORRECTION']);
    });

    it('warns when a change leaves fewer pieces than orders hold', () => {
        assert.equal(leavesShort(2, 5), true);
        assert.equal(leavesShort(5, 5), false);
        assert.equal(leavesShort(0, 0), false);
    });
});
