/*
| The last past day a daily report can be made for (resources/js/lib/daily-report-days.ts; ops.md §1.11, §4.15 O9), kept
| to what the server takes. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { lastPastDay } from '../../resources/js/lib/daily-report-days';

const ZONES = { sa: 'Asia/Riyadh', ae: 'Asia/Dubai', '': 'Asia/Riyadh' };
// 20:30 UTC on 9 November: Riyadh is still on the 9th (23:30), Dubai already on the 10th (00:30).
const EVENING = Date.UTC(2026, 10, 9, 20, 30);

describe('the last past day', () => {
    it('is yesterday in the store\'s own time', () => {
        assert.equal(lastPastDay('sa', ZONES, EVENING), '2026-11-08');
        assert.equal(lastPastDay('ae', ZONES, EVENING), '2026-11-09');
    });

    it('for every store\'s, is the day before the earliest today among them', () => {
        assert.equal(lastPastDay('', ZONES, EVENING), '2026-11-08');
    });

    it('crosses a month', () => {
        assert.equal(lastPastDay('sa', ZONES, Date.UTC(2026, 11, 1, 12)), '2026-11-30');
    });

    it('answers for someone with no store on offer, rather than failing', () => {
        assert.equal(lastPastDay('', {}, EVENING), '2026-11-08');
    });
});
