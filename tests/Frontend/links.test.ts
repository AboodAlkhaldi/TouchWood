/*
| A link as staff type it (resources/js/components/content/links.ts; content.md §1.1 rule 16), against the case table
| the server's own test reads (tests/Modules/Content/Unit/link-cases.json, RichTextLinkCasesTest): what one refuses,
| the other refuses. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { isHttpsLink, shopLink } from '../../resources/js/components/content/links';
import table from '../Modules/Content/Unit/link-cases.json';

describe('a link staff type', () => {
    for (const { typed, kept } of table.cases) {
        it(`keeps ${JSON.stringify(typed)} as the server does`, () => {
            assert.equal(shopLink(typed), kept);
        });
    }

    it('takes an https address only where a client site is asked for, as Words::httpsLink does', () => {
        assert.equal(isHttpsLink('https://client.example/work'), true);
        assert.equal(isHttpsLink('HTTPS://client.example'), true);
        assert.equal(isHttpsLink('http://client.example'), false);
        assert.equal(isHttpsLink('/projects/other'), false);
        assert.equal(isHttpsLink('client.example'), false);
        assert.equal(isHttpsLink('https:///x'), false);
    });

    it('counts its 2,000 characters as the server does, one each however JavaScript stores them', () => {
        assert.equal(shopLink(`/${'a'.repeat(1999)}`), `/${'a'.repeat(1999)}`);
        assert.equal(shopLink(`/${'a'.repeat(2000)}`), null);
        assert.equal(shopLink(`/${String.fromCodePoint(0x1f600).repeat(1999)}`), `/${String.fromCodePoint(0x1f600).repeat(1999)}`);
    });
});
