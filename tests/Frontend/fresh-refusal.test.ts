/*
| A dialog or a form says only a refusal that arrived while it was open (resources/js/lib/fresh-refusal.ts,
| behind components/FormError.tsx's useFreshRefusal): the renders a dialog goes through, played in order, as
| the hook plays them - the mark of this render, then the refusal said with it. Run by tests/Frontend/run.mjs,
| inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { freshRefusal, type OpenedAt, openedAtNow } from '@/lib/fresh-refusal';

/** A component using the hook: each render takes the mark and says what the hook would. */
function dialog() {
    let mark: OpenedAt = null;

    return (open: boolean, visits: number, message: string | undefined): string | undefined => {
        mark = openedAtNow(mark, open, visits);

        return freshRefusal(mark, visits, message);
    };
}

describe('a refusal said only by what it answers', () => {
    it('says the refusal of what was sent from the dialog, once its visit has finished', () => {
        const render = dialog();

        assert.equal(render(false, 0, undefined), undefined);
        assert.equal(render(true, 0, undefined), undefined);
        // Sent, refused: the visit finishes with the page's refusal.
        assert.equal(render(true, 1, 'Couldn’t end it.'), 'Couldn’t end it.');
    });

    it('never says a refusal from before it opened - not on the very render it opens', () => {
        const render = dialog();

        // Another dialog's refusal is on the page: two visits have finished.
        render(false, 2, 'Another dialog’s refusal.');
        // The render that opens it says nothing, and nor do the ones after while nothing finishes.
        assert.equal(render(true, 2, 'Another dialog’s refusal.'), undefined);
        assert.equal(render(true, 2, 'Another dialog’s refusal.'), undefined);
    });

    it('starts clean when opened again: its own refusal from last time is not said on the first render', () => {
        const render = dialog();

        render(true, 0, undefined);
        assert.equal(render(true, 1, 'Refused the first time.'), 'Refused the first time.');
        // Cancelled, and a visit elsewhere finishes; the refusal is still on the page.
        assert.equal(render(false, 1, 'Refused the first time.'), undefined);
        render(false, 3, 'Refused the first time.');
        // Opened again: the mark is this opening's (3), not the last one's (0), from the first render on.
        assert.equal(render(true, 3, 'Refused the first time.'), undefined);
        assert.equal(render(true, 4, 'Refused again.'), 'Refused again.');
    });

    it('keeps its mark while it stays open, and says nothing once closed or for an empty refusal', () => {
        assert.equal(openedAtNow(null, true, 5), 5);
        assert.equal(openedAtNow(5, true, 9), 5);
        assert.equal(openedAtNow(5, false, 9), null);
        assert.equal(freshRefusal(null, 9, 'Refused.'), undefined);
        assert.equal(freshRefusal(5, 9, ''), undefined);
        assert.equal(freshRefusal(5, 9, undefined), undefined);
    });
});
