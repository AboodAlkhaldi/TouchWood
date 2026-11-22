/*
| What a pasted YouTube link keeps (flags-2026-11.md S4.4): the video's 11-character id, read as Platform's YouTubeLink
| reads it - both read tests/Modules/Platform/Unit/youtube-link-cases.json, so the panel's check as it is typed and the
| server agree. Only the panel's video lists need it.
|
| Read as the text is, as PHP's parse_url reads it - never through the browser's URL, which decodes `%51` to `Q`, takes
| `\` for `/` and folds `/a/../b` into `/b`: links the server refuses would pass here (the review of 5b).
*/

const ID = /^[A-Za-z0-9_-]{11}$/;
const WATCH_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];
const EMBED_HOSTS = [...WATCH_HOSTS, 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
// A link's parts: scheme, the host with whatever may sit beside it, the path, the parameters; a fragment after them.
const PARTS = /^([a-z][a-z0-9+.-]*):\/\/([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#.*)?$/i;

/** The video's id, or null when the text is not a link to one video on YouTube. */
export function youTubeId(pasted: string): string | null {
    const typed = pasted.trim();

    if (typed === '' || typed.length > 2000 || /[\s\p{Cc}]/u.test(typed)) {
        return null;
    }

    const parts = PARTS.exec(/^[a-z][a-z0-9+.-]*:\/\//i.test(typed) ? typed : `https://${typed}`);
    const scheme = parts?.[1]?.toLowerCase();
    const authority = parts?.[2] ?? '';

    if (parts === null || (scheme !== 'https' && scheme !== 'http')) {
        return null;
    }

    // The host whole, as it is written: a name and password (`@`) or a port (`:`) beside it is then no host of YouTube's.
    const host = authority.toLowerCase();
    const path = (parts[3] ?? '').replace(/\/+$/, '');
    let id: string | null = null;

    if (host === 'youtu.be') {
        id = path.slice(1);
    } else if (WATCH_HOSTS.includes(host) && path === '/watch') {
        // The `v` parameter as it is written, once: never decoded.
        const found = (parts[4] ?? '').split('&').filter((pair) => pair.split('=', 1)[0] === 'v');
        id = found.length === 1 ? (found[0] ?? '').slice(2) : null;
    } else if (WATCH_HOSTS.includes(host) && path.startsWith('/shorts/')) {
        id = path.slice(8);
    } else if (EMBED_HOSTS.includes(host) && path.startsWith('/embed/')) {
        id = path.slice(7);
    }

    return id !== null && ID.test(id) ? id : null;
}
