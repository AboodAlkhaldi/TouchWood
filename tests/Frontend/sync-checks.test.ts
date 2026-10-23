/*
| Sync's own rules on its screens (resources/js/pages/Sync/Admin/checks.ts; sync.md §4.1): the Provider form's
| address as typed (Y3), copied from the server's ConnectionInput::address and never stricter; what a Bad
| Value's entry says was refused (Y1); and a sent value's minus. Run by tests/Frontend/run.mjs, inside the PHP
| suite (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { addressProblem, badValuesOf, sentText, wholeStock } from '@/pages/Sync/Admin/checks';

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

    it('is what the server says was refused - the price, the stock, or both - never a guess', () => {
        assert.deepEqual(badValuesOf({ twice: [{ price: '1', stock: 1 }], count: 2 }), ['twice']);
        assert.deepEqual(badValuesOf({ price: '0', stock: 2, refused: 'price' }), ['bad_price']);
        assert.deepEqual(badValuesOf({ price: '120', stock: 2.5, refused: 'stock' }), ['bad_stock']);
        assert.deepEqual(badValuesOf({ price: '120', stock: -3, refused: 'stock' }), ['below_zero']);
        // A whole number past what a size can hold - the review of step 7: never blamed on the price.
        assert.deepEqual(badValuesOf({ price: '120', stock: 2147483648, refused: 'stock' }), ['too_large']);
        assert.deepEqual(badValuesOf({ price: '120', stock: 1e20, refused: 'stock' }), ['too_large']);
        assert.deepEqual(badValuesOf({ price: '0', stock: 2.5, refused: 'both' }), ['bad_price', 'bad_stock']);
    });

    it('says a value the provider was bound to send and did not as not sent, the other refused as before (stage 5 step 10)', () => {
        assert.deepEqual(badValuesOf({ price: null, stock: 2, refused: 'price', missing: ['price'] }), ['missing_price']);
        assert.deepEqual(badValuesOf({ price: '120', stock: null, refused: 'stock', missing: ['stock'] }), ['missing_stock']);
        assert.deepEqual(badValuesOf({ price: null, stock: null, refused: 'both', missing: ['price', 'stock'] }), ['missing_price', 'missing_stock']);
        // One missing, the other sent and refused: each its own sentence.
        assert.deepEqual(badValuesOf({ price: null, stock: -3, refused: 'both', missing: ['price'] }), ['missing_price', 'below_zero']);
        assert.deepEqual(badValuesOf({ price: '0', stock: null, refused: 'both', missing: ['stock'] }), ['bad_price', 'missing_stock']);
        // A `missing` that is not a list is not read.
        assert.deepEqual(badValuesOf({ price: '0', stock: 2, refused: 'price', missing: 'price' }), ['bad_price']);
    });

    it('reads an entry kept from before the server named it as before: the stock not whole or below 0, else the price', () => {
        assert.deepEqual(badValuesOf({ price: '120', stock: 2.5 }), ['bad_stock']);
        assert.deepEqual(badValuesOf({ price: '120', stock: -3 }), ['below_zero']);
        assert.deepEqual(badValuesOf({ price: '0', stock: 2 }), ['bad_price']);
        assert.deepEqual(badValuesOf({ price: '12.555', stock: null }), ['bad_price']);
    });
});

describe('a value the provider sent', () => {
    it('writes a leading minus as the panel does, a number or a text alike', () => {
        assert.deepEqual([sentText(-3), sentText('-3'), sentText(' -3'), sentText('3-4'), sentText('-x'), sentText(12.5)], ['−3', '−3', ' −3', '3-4', '-x', '12.5']);
    });
});
