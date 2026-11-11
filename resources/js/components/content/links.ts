/*
| A link as the server takes one (content.md §1.1 rule 16; RichText::link, which Words::link calls): the same rule
| written twice, kept apart from the forms' parts so the screens' unit tests read it without React. One case table,
| tests/Modules/Content/Unit/link-cases.json, is checked against both (RichTextLinkCasesTest, links.test.ts).
|
| - Trimmed of ASCII whitespace only, as PHP's trim(): a no-break space at an end stays, and is refused below.
| - One line: no space of any kind, no control or invisible format character, no backslash; 2,000 characters at most.
| - A full https:// address: its authority, up to the first "/", "?" or "#", is a host - a name, or an address in
|   brackets - with a port of digits at most. No user part (`https://shop@evil.example`), no empty host
|   (`https:///x`, `https://@x`).
| - Otherwise a page of the shop, kept with its leading slash: no scheme, never "//".
*/

const MAX_LINK = 2000;

const AUTHORITY = /^(?:\[[^\]/?#@]+\]|[^[\]/?#@:]+)(?::[0-9]*)?(?:[/?#]|$)/u;

/** The link as the server keeps it - a page with its leading slash, or the https address - or null when refused. */
export function shopLink(value: string): string | null {
    const address = value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

    if (address === '' || [...address].length > MAX_LINK || address.includes('\\') || /[\s\p{Cc}\p{Cf}]/u.test(address)) {
        return null;
    }

    if (address.slice(0, 8).replace(/[A-Z]/g, (letter) => letter.toLowerCase()) === 'https://') {
        return AUTHORITY.test(address.slice(8)) ? address : null;
    }

    const page = `/${address.replace(/^\/+/, '')}`;

    return address.startsWith('//') || page.includes(':') || page.includes('//') ? null : page;
}

export const isShopLink = (value: string): boolean => shopLink(value) !== null;
