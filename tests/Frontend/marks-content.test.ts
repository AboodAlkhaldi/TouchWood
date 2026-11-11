/*
| A page's, post's or project's body as staff type it (resources/js/components/content/marks.ts; content.md §1.2),
| against the case table the server's own test reads (tests/Modules/Content/Unit/mark-cases.json, MarkTextCasesTest):
| the editor's preview draws the blocks the server keeps. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { readContentMarks } from '../../resources/js/components/content/marks';
import table from '../Modules/Content/Unit/mark-cases.json';

describe('a body staff type', () => {
    for (const { typed, blocks } of table.cases) {
        it(`reads ${JSON.stringify(typed).slice(0, 60)} as the server does`, () => {
            assert.deepEqual(readContentMarks(typed), blocks);
        });
    }
});
