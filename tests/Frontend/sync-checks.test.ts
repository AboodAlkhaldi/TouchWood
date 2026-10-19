/*
| Sync's own rules on its screens (resources/js/pages/Sync/Admin/checks.ts; sync.md §4.1): the Provider form's
| address as typed (Y3), copied from the server's ConnectionInput::address and never stricter; and which of a
| Bad Value's values the Sync Report says was refused (Y1). Run by tests/Frontend/run.mjs, inside the PHP
| suite (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { addressProblem, badValueOf, wholeStock } from '@/pages/Sync/Admin/checks';

const P = 'sync::admin_provider.checks';

describe('the Provider form\'s address', () => {
    it('takes an HTTPS address with a host, a path, a query, and an "@" after the host as the server does', () => {
        for (const good of ['https://odoo.example.com', 'HTTPS://odoo.example.com/api', 'https://odoo.example.com:8443/api?db=tw#x', 'https://odoo.example.com/a@b']) {
            assert.equal(addressProblem(good), null, good);
        }
    });

    it('says each rule it breaks in its own words', () => {
        assert.equal(addressProblem('http://odoo.example.com'), `${P}.address_https`);
        assert.equal(addressProblem('odoo.example.com'), `${P}.address_https`);
        assert.equal(addressProblem('https://'), `${P}.address_host`);
        assert.equal(addressProblem('https:///api'), `${P}.address_host`);
        assert.equal(addressProblem('https://odoo example.com'), `${P}.address_plain`);
        assert.equal(addressProblem('https://odoo.example.com/a b'), `${P}.address_plain`);
        assert.equal(addressProblem('https://user:pass@odoo.example.com'), `${P}.address_plain`);
        assert.equal(addressProblem(`https://odoo.example.com/${'a'.repeat(480)}`), `${P}.address_long`);
    });
});

describe('a Bad Value\'s refused value', () => {
    it('reads a stock number as Sync does: 5, "5", "-3" whole; 5.5, "5.0", "five" not', () => {
        assert.deepEqual([wholeStock(5), wholeStock('5'), wholeStock(' -3 '), wholeStock(5.5), wholeStock('5.0'), wholeStock('five')], [5, 5, -3, null, null, null]);
    });

    it('is the code sent twice, the stock that is not whole, the stock below 0, else the price', () => {
        assert.equal(badValueOf({ twice: [{ price: '1', stock: 1 }], count: 2 }), 'twice');
        assert.equal(badValueOf({ price: '120', stock: 2.5 }), 'bad_stock');
        assert.equal(badValueOf({ price: '120', stock: -3 }), 'below_zero');
        assert.equal(badValueOf({ price: '0', stock: 2 }), 'bad_price');
        assert.equal(badValueOf({ price: '12.555', stock: null }), 'bad_price');
    });
});
