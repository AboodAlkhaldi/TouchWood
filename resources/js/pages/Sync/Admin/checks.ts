/*
| Sync's own rules on its screens (sync.md §4.1), kept apart from the pages so they are tested on their own
| (tests/Frontend/sync-checks.test.ts): the Provider form's address as typed (Y3), and what a Bad Value's
| entry says was refused (Y1 "Detail per kind"); the minus a sent value is written with. The server decides; these copy its rules, never stricter.
*/

const P = 'sync::admin_provider';

/**
 * An address as the server takes one (ConnectionInput::address: `https://` then a host with no space, "/",
 * "?", "#" or "@", then anything but a space; 500 characters at most), each rule its own sentence's key.
 * An "@" after the host is the server's to take, so it is not refused here.
 */
export function addressProblem(text: string): string | null {
    if (!/^https:\/\//i.test(text)) {
        return `${P}.checks.address_https`;
    }

    const host = text.slice('https://'.length).split(/[/?#]/)[0] ?? '';

    if (host === '') {
        return `${P}.checks.address_host`;
    }

    if (/\s/.test(text) || host.includes('@')) {
        return `${P}.checks.address_plain`;
    }

    return [...text].length > 500 ? `${P}.checks.address_long` : null;
}

/** A stock number the provider wrote, read as Sync reads one (RunReadHandler::whole): a whole number, or null. */
export function wholeStock(value: unknown): number | null {
    if (typeof value === 'number') {
        return Number.isInteger(value) ? value : null;
    }

    return typeof value === 'string' && /^\s*-?\d{1,18}\s*$/.test(value) ? Number(value.trim()) : null;
}

export type BadValue = 'twice' | 'bad_price' | 'bad_stock' | 'below_zero' | 'too_large' | 'missing_price' | 'missing_stock';

/** Why a stock number the server refused was refused: not a whole number (skipped), below 0 (kept as 0), or too large. */
function stockRefusal(stock: unknown): BadValue {
    const number = wholeStock(stock);

    return number === null ? 'bad_stock' : number < 0 ? 'below_zero' : 'too_large';
}

/**
 * What a Bad Value's entry says was refused (sync.md §1.5 #2, §2.4): a code sent twice; else the value the
 * server names in `refused` - the price, the stock, or both, each with its own sentence; one the provider
 * was bound to send and did not, named in `missing` (Odoo's, sync.md §2.4 As built), said as not sent. An
 * entry kept from before the server named it (screens step 7's review) is read as before: the stock when it
 * is not a whole number or is below 0, else the price.
 */
export function badValuesOf(detail: Record<string, unknown>): BadValue[] {
    if (Array.isArray(detail.twice)) {
        return ['twice'];
    }

    const missing = Array.isArray(detail.missing) ? detail.missing : [];
    const price = (): BadValue => (missing.includes('price') ? 'missing_price' : 'bad_price');
    const stock = (): BadValue => (missing.includes('stock') ? 'missing_stock' : stockRefusal(detail.stock));

    switch (detail.refused) {
        case 'price':
            return [price()];
        case 'stock':
            return [stock()];
        case 'both':
            return [price(), stock()];
        default: {
            const number = detail.stock === null || detail.stock === undefined ? 0 : wholeStock(detail.stock);

            return [number === null ? 'bad_stock' : number < 0 ? 'below_zero' : 'bad_price'];
        }
    }
}

/** A value the provider sent, as text: a leading minus written as the panel writes one, "−3" (pricing.md §4.3.1). */
export function sentText(value: string | number): string {
    return String(value).replace(/^(\s*)-(?=\d)/, '$1−');
}
