/*
| A search's reads as they are typed (Content's Add Product and a campaign's target picker; the #386 review): each read
| is numbered when it starts, and only the latest one's answer may be taken - "dra" answering after "drawer" is dropped,
| and so is any read still on its way when the search is cleared. `useLiveRead` (lib/live-read.ts) does the same for a
| read that follows one value; this is for a list read page by page.
*/

export type LatestReads = {
    /** A read starting: the answer of this one may be taken while it says true. */
    start: () => () => boolean;
    /** Every read on its way is no longer wanted. */
    drop: () => void;
};

export function latestReads(): LatestReads {
    let latest = 0;

    return {
        start: () => {
            const mine = ++latest;

            return () => mine === latest;
        },
        drop: () => {
            latest++;
        },
    };
}
