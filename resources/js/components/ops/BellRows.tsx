import { useRef, useState, type MouseEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Time } from '@/components/Time';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemTitle } from '@/components/ui/item';
import { useBellChanges } from '@/lib/ops-bell';
import { markRow } from '@/lib/ops-bell-marks';
import type { InboxEntryData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { BellWords, SharedProps } from '@/types/page';

/*
| The rows of a bell or of its full page (ops.md §4.15 O1, O2), on shadcn's Item: the title, the line, the
| time and, for someone with two or more stores, the store. Pressing a row marks it read; with a link it
| goes there, a failed mark still going; with none it stays, and opens to show its whole text.
*/

/** The rows, with whatever was marked since they arrived. */
function useBellRows(entries: InboxEntryData[]): InboxEntryData[] {
    const [marked, setMarked] = useState<ReadonlySet<string>>(new Set());
    const shown = useRef(entries);
    shown.current = entries;

    useBellChanges((change) => {
        const ids = change.all === true ? shown.current.map((entry) => entry.id) : change.read === undefined ? [] : [change.read];

        if (ids.length > 0) {
            setMarked((before) => new Set([...before, ...ids]));
        }
    });

    return entries.map((entry) => (marked.has(entry.id) && !entry.read ? { ...entry, read: true } : entry));
}

type Props = {
    entries: InboxEntryData[];
    base: string;
    words: BellWords;
    /** Two or more stores (Q9): each row says its store. */
    manyStores?: boolean;
    /** The full page's rows: the whole text, never cut. */
    whole?: boolean;
    /** Before going to a row's link: the bell shuts itself. */
    onGo?: () => void;
};

export function BellRows({ entries, base, words, manyStores = false, whole = false, onGo }: Props) {
    const { csrfToken } = usePage<SharedProps>().props;
    const rows = useBellRows(entries);
    const [opened, setOpened] = useState<ReadonlySet<string>>(new Set());
    // The row on its way: a second press would send a second mark and a second visit.
    const [going, setGoing] = useState<string | null>(null);

    function marked(entry: InboxEntryData): Promise<void> {
        return entry.read ? Promise.resolve() : markRow(base, entry.id, csrfToken);
    }

    function go(event: MouseEvent, entry: InboxEntryData, link: string) {
        // Opened in another tab: the browser goes there, and this page stays.
        if (event.metaKey || event.ctrlKey || event.shiftKey) {
            void marked(entry);

            return;
        }

        event.preventDefault();

        if (going !== null) {
            return;
        }

        setGoing(entry.id);
        void marked(entry).finally(() => {
            setGoing(null);
            onGo?.();
            router.visit(link);
        });
    }

    function open(entry: InboxEntryData) {
        void marked(entry);

        if (whole) {
            return;
        }

        setOpened((before) => {
            const after = new Set(before);

            if (!after.delete(entry.id)) {
                after.add(entry.id);
            }

            return after;
        });
    }

    return (
        <ItemGroup className="gap-1" data-test="bell-rows">
            {rows.map((entry) => {
                const isOpen = whole || opened.has(entry.id);
                const inner = (
                    <ItemContent className="min-w-0">
                        <ItemTitle className={entry.read ? 'w-full font-normal wrap-anywhere' : 'w-full font-semibold wrap-anywhere'}>
                            <span className="min-w-0">
                                {entry.title}
                                {entry.read ? null : <span className="sr-only"> ({words.unread})</span>}
                            </span>
                        </ItemTitle>
                        <ItemDescription className={isOpen ? 'line-clamp-none wrap-anywhere whitespace-pre-line text-pretty' : 'line-clamp-3 wrap-anywhere text-pretty'}>{entry.line}</ItemDescription>
                        <span className="flex flex-wrap items-center gap-x-2 text-label-12 text-muted-foreground">
                            {manyStores && entry.storeName !== '' ? <span data-test="bell-row-store">{entry.storeName}</span> : null}
                            <Time value={entry.createdAt} focusable={false} />
                        </span>
                    </ItemContent>
                );
                const look = entry.read ? 'w-full text-start hover:bg-muted/50' : 'w-full bg-muted text-start hover:bg-muted/80';

                return (
                    <div role="listitem" key={entry.id} data-test="bell-row" data-read={entry.read ? 'true' : 'false'}>
                        {entry.link === null ? (
                            <Item asChild size="sm" className={look}>
                                {/* On the full page a read row has nothing more to do; it stays, so focus has somewhere to be. */}
                                <button type="button" aria-expanded={whole ? undefined : isOpen} aria-disabled={whole && entry.read ? true : undefined} onClick={() => open(entry)}>
                                    {inner}
                                </button>
                            </Item>
                        ) : (
                            <Item asChild size="sm" className={look}>
                                <a
                                    href={entry.link}
                                    aria-busy={going === entry.id}
                                    onClick={(event) => go(event, entry, entry.link ?? '')}
                                    // The middle button fires this, not a click.
                                    onAuxClick={(event) => {
                                        if (event.button === 1) {
                                            void marked(entry);
                                        }
                                    }}
                                >
                                    {inner}
                                </a>
                            </Item>
                        )}
                    </div>
                );
            })}
        </ItemGroup>
    );
}
