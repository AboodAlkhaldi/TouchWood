/*
| A notice's link as it is typed (ops.md §4.4, §4.15 O6): a page of the shop, without its store and language. A whole
| pasted shop address is cut down to that part; an address of another store or another site is said, not sent. The rules
| are the server's: at most 500 characters, no `//`, backslash, `:` or white space, a leading `/` dropped - and no `..`,
| which would climb out of the shop's pages.
*/

export type NoticeLink = { part: string; problem: 'other_site' | 'other_store' | 'rule' | null };

const WHOLE = /^[a-z][a-z0-9+.-]*:\/\//i;
const STORE_AND_LANGUAGE = /^([a-z]{2,8})\/(?:ar|en)(?:[/?#](.*))?$/;
const REFUSED = /\/\/|\\|:|\s/;
const CLIMBS = /(^|\/)\.\.(\/|$)/;

/**
 * @param stores  the stores the form knows: a page's own first words ("products/en") aren't taken for a store's address
 */
export function readNoticeLink(typed: string, storeCode: string, origin: string, stores: string[] = [storeCode]): NoticeLink {
    const text = typed.trim();

    if (text === '') {
        return { part: '', problem: null };
    }

    const whole = WHOLE.test(text) || text.startsWith('//');
    let path = text;

    if (whole) {
        let url: URL;

        try {
            url = new URL(text, origin);
        } catch {
            return { part: '', problem: 'rule' };
        }

        if (url.origin !== origin) {
            return { part: '', problem: 'other_site' };
        }

        path = `${url.pathname}${url.search}${url.hash}`;
    }

    path = path.replace(/^\/+/, '');

    if (CLIMBS.test(path)) {
        return { part: '', problem: 'rule' };
    }

    const parts = STORE_AND_LANGUAGE.exec(path);

    if (parts !== null && (whole || stores.includes(parts[1] as string))) {
        if (parts[1] !== storeCode) {
            return { part: '', problem: 'other_store' };
        }

        path = parts[2] ?? '';
    } else if (whole) {
        // This site, but no page of a shop: the panel, say.
        return { part: '', problem: 'rule' };
    } else if ((path.split('/')[0] ?? '').includes('.')) {
        // An address typed without its https://.
        return { part: '', problem: 'other_site' };
    }

    return path.length > 500 || REFUSED.test(path) ? { part: '', problem: 'rule' } : { part: path, problem: null };
}
