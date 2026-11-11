/*
| A search's reads as they are typed (resources/js/lib/latest-read.ts; PR 7b's review): only the latest read's answer is
| taken, whatever order the answers arrive in, and a read still on its way when the search is cleared is dropped. Run by
| tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { latestReads } from '../../resources/js/lib/latest-read';

describe('a search read as it is typed', () => {
    it('takes the answer of the latest read only, "dra" answering after "drawer" dropped', () => {
        const reads = latestReads();
        const dra = reads.start();
        const drawer = reads.start();

        assert.equal(drawer(), true);
        assert.equal(dra(), false);
    });

    it('drops a read still on its way when the search is cleared', () => {
        const reads = latestReads();
        const drawer = reads.start();
        reads.drop();

        assert.equal(drawer(), false);
        assert.equal(reads.start()(), true);
    });
});
