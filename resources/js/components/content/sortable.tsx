import { lazy, Suspense, useEffect, useState, type ComponentProps } from 'react';

/*
| Content's lists put in order by dragging (content.md §4.2): Catalog's `SortableList`, its @dnd-kit loaded only once
| the page is drawn. The server's render and the first paint show the same rows as a plain list, room kept for each
| handle (lessons 180, 395: a lazy part on first paint breaks the server's render, so it waits for the mount). Move to
| Top and Move to Bottom (`MoveItems`) come from the same file when a row's ⋯ menu first opens, which is never on first
| paint. A page with such a list pays for no dragging in its own 60 KB (JavaScriptBudgetTest).
*/

const sortable = () => import('@/pages/Catalog/Admin/SortableList');
const Draggable = lazy(() => sortable().then((module) => ({ default: module.SortableList })));
const Moves = lazy(() => sortable().then((module) => ({ default: module.MoveItems })));

/** Move to Top and Move to Bottom, for a row's ⋯ menu: loaded as the menu opens. */
export function MoveItems(props: ComponentProps<typeof Moves>) {
    return (
        <Suspense fallback={null}>
            <Moves {...props} />
        </Suspense>
    );
}

type Props = ComponentProps<typeof Draggable>;

export function SortableList(props: Props) {
    const [mounted, setMounted] = useState(false);

    useEffect(() => setMounted(true), []);

    if (!mounted) {
        return <PlainList {...props} />;
    }

    return (
        <Suspense fallback={<PlainList {...props} />}>
            <Draggable {...props} />
        </Suspense>
    );
}

/**
 * The rows as SortableList draws them, before its dragging has loaded: the handle's room kept, so nothing moves when it
 * comes. Its icon comes with it - drawn here, it would be a chunk of its own on every page that drags (JavaScriptBudgetTest).
 */
function PlainList({ items, testPrefix = 'sortable', layout = 'list' }: Props) {
    return (
        <div role="list" className={layout === 'list' ? 'grid gap-2' : 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5'}>
            {items.map((item, index) => (
                <div key={item.id} role="listitem" data-test={`${testPrefix}-${index}`} className={layout === 'list' ? 'material-small relative z-0 flex items-center gap-2 px-2 py-1.5' : 'material-small relative z-0 grid gap-2 p-2'}>
                    <span className="size-8 shrink-0" aria-hidden="true" />
                    {item.content ?? <span className="text-copy-14 text-ink">{item.label}</span>}
                    {item.extra ? <span className={layout === 'list' ? 'ms-auto' : undefined}>{item.extra}</span> : null}
                </div>
            ))}
        </div>
    );
}
