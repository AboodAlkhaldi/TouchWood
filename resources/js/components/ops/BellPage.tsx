import { useEffect, useRef } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { BellRows } from '@/components/ops/BellRows';
import { ask, tell, useBellNumber } from '@/lib/ops-bell';
import { useMarkAll } from '@/lib/ops-bell-marks';
import { useLoadMore } from '@/lib/use-load-more';
import type { InboxPageData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { BellWords } from '@/types/page';

/*
| What the two full pages behind View All share (ops.md §4.15 O1, O2): Mark All as Read at the top, the
| rows with their whole text, and Show More. Opening a page marks nothing; no store filter (§4.13 #4).
*/

type Props = {
    inbox: InboxPageData;
    base: string;
    words: BellWords;
    manyStores?: boolean;
};

export function BellPage({ inbox, base, words, manyStores = false }: Props) {
    const unread = useBellNumber(inbox.unread);
    const { rows, loading, more } = useLoadMore(inbox.entries, (entry) => entry.id);
    const list = useRef<HTMLDivElement>(null);
    const { marking, failed, markAll } = useMarkAll(base, () => list.current?.focus());

    // Show More's answer brings a fresh number: the bell above the page gets it too.
    const shown = useRef(inbox.unread);
    useEffect(() => {
        if (shown.current !== inbox.unread) {
            shown.current = inbox.unread;
            tell(ask(), { unread: inbox.unread });
        }
    }, [inbox.unread]);

    return (
        <div ref={list} tabIndex={-1} className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-4 outline-none" data-test="bell-page">
            {unread > 0 ? (
                <div className="flex justify-end">
                    <ActionButton type="button" variant="outline" size="sm" loading={marking} onClick={markAll} data-test="bell-page-mark-all">
                        {words.mark_all}
                    </ActionButton>
                </div>
            ) : null}
            {failed ? (
                <p role="alert" className="text-copy-14 text-bad" data-test="bell-page-mark-failed">
                    {words.mark_failed}
                </p>
            ) : null}

            {rows.length === 0 ? (
                <p className="text-copy-14 text-muted-foreground" data-test="bell-page-empty">
                    {words.empty}
                </p>
            ) : (
                <BellRows entries={rows} base={base} words={words} manyStores={manyStores} whole />
            )}

            {inbox.next === null ? null : <LoadMoreButton loading={loading} onClick={() => more(base, { after: inbox.next }, ['inbox'])} />}
        </div>
    );
}
