/*
| How many characters Catalog's server counts in a description typed with marks (StructuredText;
| resources/js/pages/Catalog/Admin/marks.ts), so a box holding terms says "at most 5,000 characters"
| exactly when the server would refuse them (frontend.md §1.7).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { marksLength } from '@/pages/Catalog/Admin/marks';

describe('the characters of a text with marks', () => {
    it('counts the text, not the marks', () => {
        assert.equal(marksLength('# Care'), 4);
        assert.equal(marksLength('- one\n- two'), 6);
        assert.equal(marksLength('**bold** text'), 9);
    });

    it('counts the space that joins a paragraph\'s lines, not the blank line between paragraphs', () => {
        assert.equal(marksLength('ab\ncd'), 5);
        assert.equal(marksLength('ab\n\ncd'), 4);
    });

    it('counts each character once, however JavaScript stores it', () => {
        assert.equal(marksLength(String.fromCodePoint(0x1f600, 0x1f600)), 2);
    });

    it('is nothing for nothing', () => {
        assert.equal(marksLength(''), 0);
        assert.equal(marksLength('\n\n'), 0);
    });
});
