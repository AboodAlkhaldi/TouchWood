/*
| The sidebar lights the entry being read, and only it (resources/js/lib/menu-here.ts; the owner, 2026-10-19):
| the longest address that holds the path wins, so /admin/sync/provider lights the Provider and not the Sync
| Report at /admin/sync too. Run by tests/Frontend/run.mjs, inside the PHP suite.
*/

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { entryHere, holds } from '@/lib/menu-here';
import type { MenuEntry, MenuGroup } from '@/types/page';

/** The admin menu's addresses as the modules register them (2026-10-19), by business area. */
const AREAS: Record<string, string[]> = {
    catalog: [
        '/admin/products',
        '/admin/categories',
        '/admin/brands',
        '/admin/attributes',
        '/admin/labels',
        '/admin/warranties',
        '/admin/search-words',
        '/admin/store-files',
        '/admin/imports',
        '/admin/stock',
        '/admin/low-stock',
    ],
    pricing: ['/admin/prices', '/admin/offers', '/admin/category-discounts', '/admin/wholesale-prices', '/admin/needs-a-price'],
    customers: ['/admin/customers'],
    companies: ['/admin/companies', '/admin/company-types', '/admin/document-types'],
    points: ['/admin/points/customers', '/admin/points/redemptions', '/admin/points/settings'],
    staff_and_permissions: ['/admin/staff', '/admin/roles'],
    sync: ['/admin/sync', '/admin/sync/provider'],
    store_settings: ['/admin/stores', '/admin/currencies', '/admin/settings', '/admin/address-formats'],
    media: ['/admin/media'],
    audit: ['/admin/audit'],
    system: ['/admin/failed-jobs'],
};

function entry(href: string): MenuEntry {
    return { module: 'm', key: href, label: href, href, comingSoon: false, icon: null, count: null };
}

const MENU: MenuGroup[] = Object.entries(AREAS).map(([key, hrefs]) => ({ key, label: key, entries: hrefs.map(entry) }));

/** Every entry that holds a path, as the sidebar lit them before (a prefix alone). */
function holders(here: string): string[] {
    return MENU.flatMap((group) => group.entries.filter((each) => holds(here, each)).map((each) => each.href));
}

describe('the entry being read', () => {
    it('lights the Provider alone on its own page, and the Sync Report on its own', () => {
        assert.deepEqual(holders('/admin/sync/provider'), ['/admin/sync', '/admin/sync/provider']);
        assert.deepEqual(entryHere(MENU, '/admin/sync/provider'), { group: 'sync', entry: entry('/admin/sync/provider') });
        assert.deepEqual(entryHere(MENU, '/admin/sync'), { group: 'sync', entry: entry('/admin/sync') });
    });

    it('lights the entry a page sits under: a read under the Sync Report, a staff member under Staff', () => {
        assert.equal(entryHere(MENU, '/admin/sync/reads/01jbread')?.entry.href, '/admin/sync');
        assert.equal(entryHere(MENU, '/admin/staff/01jbstaff/role')?.entry.href, '/admin/staff');
        assert.equal(entryHere(MENU, '/admin/stock/history')?.entry.href, '/admin/stock');
    });

    it('lights each entry alone on its own screen, in every area', () => {
        for (const group of MENU) {
            for (const each of group.entries) {
                assert.deepEqual(entryHere(MENU, each.href), { group: group.key, entry: each }, each.href);
            }
        }
    });

    it('never takes a shared beginning for a page underneath: Stock is not under Low Stock, nor Customers under Points', () => {
        assert.equal(entryHere(MENU, '/admin/low-stock')?.entry.href, '/admin/low-stock');
        assert.equal(entryHere(MENU, '/admin/points/customers/01jbcustomer')?.entry.href, '/admin/points/customers');
        assert.equal(entryHere(MENU, '/admin/syncing'), null);
    });

    it('lights nothing on Home or a page no entry holds', () => {
        assert.equal(entryHere(MENU, '/admin'), null);
        assert.equal(entryHere(MENU, '/admin/account'), null);
        assert.equal(entryHere([], '/admin/sync'), null);
    });
});
