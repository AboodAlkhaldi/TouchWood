/*
| A pasted YouTube link (resources/js/lib/youtube-id.ts, and the addresses of lib/youtube.ts; flags-2026-11.md S4.4), against the case table the server's own
| test reads (tests/Modules/Platform/Unit/youtube-link-cases.json, YouTubeLinkTest): what one refuses, the other
| refuses, and both keep the same id. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { youTubeLink, youTubePlayer, youTubeThumbnail } from '../../resources/js/lib/youtube';
import { youTubeId } from '../../resources/js/lib/youtube-id';
import table from '../Modules/Platform/Unit/youtube-link-cases.json';

describe('a pasted YouTube link', () => {
    it('reads a table with both kinds of case in it', () => {
        assert.ok(table.cases.length > 30);
        assert.ok(table.cases.some((each) => each.id === null));
        assert.ok(table.cases.some((each) => each.id !== null));
    });

    for (const { pasted, id } of table.cases) {
        it(`keeps ${JSON.stringify(pasted)} as the server does`, () => {
            assert.equal(youTubeId(pasted), id);
        });
    }

    it('refuses a text far longer than any link YouTube gives', () => {
        assert.equal(youTubeId(`https://youtu.be/dQw4w9WgXcQ?${'a'.repeat(2000)}`), null);
    });

    it('sends a kept video back as a link the server reads to the same id', () => {
        assert.equal(youTubeId(youTubeLink('a-B_c1D2e3F')), 'a-B_c1D2e3F');
    });

    it('draws the thumbnail from YouTube\'s image host and the player from the address without cookies', () => {
        assert.equal(youTubeThumbnail('dQw4w9WgXcQ'), 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
        assert.equal(youTubePlayer('dQw4w9WgXcQ'), 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0');
    });
});
