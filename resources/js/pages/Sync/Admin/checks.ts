/*
| Sync's own rules on its screens (sync.md §4.1), kept apart from the pages so they are tested on their own
| (tests/Frontend/sync-checks.test.ts): the Provider form's address as typed (Y3), and which of a Bad Value's
| values was refused (Y1 "Detail per kind"). The server decides; these copy its rules, never stricter.
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

/**
 * Which of a Bad Value's values was refused (sync.md §1.5 #2, §2.4), from what the entry keeps: a code sent
 * twice; a stock number that is not a whole one (skipped) or is below 0 (kept as 0); else the price.
 */
export function badValueOf(detail: Record<string, unknown>): 'twice' | 'bad_stock' | 'below_zero' | 'bad_price' {
    if (Array.isArray(detail.twice)) {
        return 'twice';
    }

    const stock = detail.stock;

    if (stock !== null && stock !== undefined) {
        const number = wholeStock(stock);

        if (number === null) {
            return 'bad_stock';
        }

        if (number < 0) {
            return 'below_zero';
        }
    }

    return 'bad_price';
}
