/*
| A notice's link as it is typed (resources/js/lib/notice-link.ts; ops.md §4.4, §4.15 O6): a whole shop address cut
| down to its page, another store's or another site's said, and the server's rules. Run by tests/Frontend/run.mjs,
| inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { readNoticeLink } from '../../resources/js/lib/notice-link';

const ORIGIN = 'https://shop.example';
const read = (typed: string) => readNoticeLink(typed, 'sa', ORIGIN);

describe('a notice link', () => {
    it('is nothing when nothing is typed', () => {
        assert.deepEqual(read('   '), { part: '', problem: null });
    });

    it('keeps a page as it is written, a leading slash dropped', () => {
        assert.deepEqual(read('categories/chairs'), { part: 'categories/chairs', problem: null });
        assert.deepEqual(read('/categories/chairs'), { part: 'categories/chairs', problem: null });
    });

    it('cuts a whole address of this store down to its page, in either language, keeping its query', () => {
        assert.deepEqual(read(`${ORIGIN}/sa/en/products/oak?size=60`), { part: 'products/oak?size=60', problem: null });
        assert.deepEqual(read(`${ORIGIN}/sa/ar/products/oak`), { part: 'products/oak', problem: null });
        assert.deepEqual(read('/sa/en/products/oak'), { part: 'products/oak', problem: null });
    });

    it('leaves nothing for the store\'s own home', () => {
        assert.deepEqual(read(`${ORIGIN}/sa/en`), { part: '', problem: null });
    });

    it('says when an address is another store\'s', () => {
        assert.equal(read(`${ORIGIN}/ae/en/products/oak`).problem, 'other_store');
        assert.equal(readNoticeLink('ae/ar/products/oak', 'sa', ORIGIN, ['sa', 'ae']).problem, 'other_store');
    });

    it('does not take a page\'s own words for a store and a language', () => {
        assert.deepEqual(read('products/en'), { part: 'products/en', problem: null });
        assert.deepEqual(read('categories/ar'), { part: 'categories/ar', problem: null });
    });

    it('says when an address is another site\'s, a shared start of the name and no https:// included', () => {
        assert.equal(read('https://elsewhere.example/sa/en/x').problem, 'other_site');
        assert.equal(read('https://shop.example.evil.test/sa/en/x').problem, 'other_site');
        assert.equal(read('//elsewhere.example/x').problem, 'other_site');
        assert.equal(read('www.instagram.com/x').problem, 'other_site');
    });

    it('refuses this site\'s pages that are no shop\'s, and a climb out of the shop', () => {
        assert.equal(read(`${ORIGIN}/admin/coupons`).problem, 'rule');
        assert.equal(read('../../admin').problem, 'rule');
        assert.equal(read('products/../../admin').problem, 'rule');
    });

    it('holds to the server\'s rules', () => {
        assert.equal(read('a b').problem, 'rule');
        assert.equal(read('a:b').problem, 'rule');
        assert.equal(read('a\\b').problem, 'rule');
        assert.equal(read('a//b').problem, 'rule');
        assert.equal(read('x'.repeat(501)).problem, 'rule');
        assert.equal(read('x'.repeat(500)).problem, null);
        assert.equal(read('javascript:alert(1)').problem, 'rule');
    });
});
