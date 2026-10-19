/*
| Pricing's boxes as they are typed (resources/js/pages/Pricing/Admin/checks.ts; pricing.md §4.3.1 "Money
| and numbers", §4.3.3 P4): a price in the store's decimals, a percentage, a size's wholesale bands. Run by
| tests/Frontend/run.mjs, inside the PHP suite (tests/Architecture/FrontendUnitTest.php).
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { bandProblems, exampleIn, minorOf, percentProblem, priceProblem, typedOf, type BandsFacts } from '@/pages/Pricing/Admin/checks';

/** The key a sentence was asked by, with its values: what the page would say. */
const t = (key: string, values: Record<string, string | number> = {}) => (Object.keys(values).length === 0 ? key : `${key} ${JSON.stringify(values)}`);

describe('a price as typed', () => {
    it('is written and read in the store\'s decimals', () => {
        assert.equal(typedOf(14950, 2), '149.50');
        assert.equal(typedOf(5, 3), '0.005');
        assert.equal(typedOf(149, 0), '149');
        assert.equal(minorOf('149.5', 2), 14950);
        assert.equal(minorOf('149.505', 2), null);
        assert.equal(minorOf('abc', 2), null);
        assert.equal(exampleIn(2), '149.50');
        assert.equal(exampleIn(3), '149.500');
        assert.equal(exampleIn(0), '149');
    });

    it('names its rule: a number like the example, the decimals, more than 0', () => {
        assert.equal(priceProblem('149.50', 2, t, 'en'), null);
        assert.equal(priceProblem('1,49', 2, t, 'en'), 'pricing::admin.check.price_number {"example":"149.50"}');
        assert.equal(priceProblem('149.505', 2, t, 'en'), 'pricing::admin.check.price_decimals.other {"count":"2"}');
        assert.equal(priceProblem('149.5', 0, t, 'en'), 'pricing::admin.check.price_whole');
        assert.equal(priceProblem('0', 2, t, 'en'), 'pricing::admin.check.price_positive');
        // A discount's amount says the same in its own words.
        assert.equal(priceProblem('0.00', 2, t, 'en', 'amount'), 'pricing::admin_discounts.check.amount_positive');
    });

    it('takes a percentage from 0.01 to 99.99, at most 2 decimals', () => {
        assert.equal(percentProblem('12.5', t), null);
        assert.equal(percentProblem('0.01', t), null);
        assert.equal(percentProblem('100', t), 'pricing::admin.check.percent_range');
        assert.equal(percentProblem('0', t), 'pricing::admin.check.percent_range');
        assert.equal(percentProblem('12.555', t), 'pricing::admin.check.percent_decimals');
    });
});

describe('a size\'s wholesale bands as typed', () => {
    const facts: BandsFacts = { retail: { text: '120.00 SAR', minor: 12000 }, minimum: 5, maximum: 50, bands: [], minimumChanged: false };

    it('passes bands that keep every rule', () => {
        assert.deepEqual(bandProblems([{ from: '5', price: '100.00' }, { from: '10', price: '95.00' }], facts, 2, t, 'en'), [{}, {}]);
    });

    it('starts at the wholesale minimum, below the retail price', () => {
        const [first] = bandProblems([{ from: '4', price: '120.00' }], facts, 2, t, 'en');

        assert.equal(first?.from, 'pricing::admin.bands_dialog.check.first {"minimum":"5"}');
        assert.equal(first?.price, 'pricing::admin.bands_dialog.check.first_price {"retail":"⁦120.00 SAR⁩"}');
    });

    it('goes up in pieces and down in price, never past the maximum', () => {
        const rows = bandProblems(
            [
                { from: '5', price: '100' },
                { from: '5', price: '101' },
                { from: '60', price: '90' },
            ],
            facts,
            2,
            t,
            'en',
        );

        assert.equal(rows[1]?.from, 'pricing::admin.bands_dialog.check.from_order');
        assert.equal(rows[1]?.price, 'pricing::admin.bands_dialog.check.price_order');
        assert.equal(rows[2]?.from, 'pricing::admin.bands_dialog.check.from_max {"maximum":"50"}');
    });

    it('takes whole pieces only', () => {
        assert.equal(bandProblems([{ from: '5.5', price: '100' }], facts, 2, t, 'en')[0]?.from, 'pricing::admin.bands_dialog.check.from_whole');
    });
});
