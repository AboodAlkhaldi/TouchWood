import type { MenuEntry, MenuGroup } from '@/types/page';

/*
| Which menu entry the person is standing on (the sidebar, components/AppSidebar.tsx; frontend.md §2.2).
|
| An entry holds its own screen and the ones underneath it (/admin/staff/{id} under /admin/staff), so
| two entries can both hold a path: /admin/sync/provider is under the Sync Report's /admin/sync too.
| **The longest one wins** - the entry whose address says the most about where the person is - and
| only it is lit (the owner, 2026-10-19). Run by tests/Frontend/menu-here.test.ts.
*/

/** Whether the person is on this entry's screen, or on one underneath it. */
export function holds(here: string, entry: Pick<MenuEntry, 'href'>): boolean {
    return here === entry.href || here.startsWith(`${entry.href}/`);
}

/** The entry being read, across every area - the longest that holds the path; null on none (Home). */
export function entryHere(menu: MenuGroup[], here: string): { group: string; entry: MenuEntry } | null {
    let best: { group: string; entry: MenuEntry } | null = null;

    for (const group of menu) {
        for (const entry of group.entries) {
            if (holds(here, entry) && (best === null || entry.href.length > best.entry.href.length)) {
                best = { group: group.key, entry };
            }
        }
    }

    return best;
}
