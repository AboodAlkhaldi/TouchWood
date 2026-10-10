import { useId, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import {
    DndContext,
    KeyboardSensor,
    MouseSensor,
    TouchSensor,
    closestCenter,
    useSensor,
    useSensors,
    type CollisionDetection,
    type DragEndEvent,
    type KeyboardCoordinateGetter,
    type UniqueIdentifier,
} from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useTranslator } from '@/lib/t';

/*
| Lists put in order by dragging (catalog.md §4.4, amendment 16(d), P8): shadcn's `dashboard-01` drag
| handles on @dnd-kit, as the address formats' fields are (frontend.md §1.11) - by mouse, touch or
| keyboard, each move said aloud in the page's language. Only the handle picks a row up.
|
| `SortableRows` holds one or more groups - a list's rows, or each parent's children in the category
| tree - and a row moves only among its own group: a drop elsewhere, by pointer or by keys, is never
| offered. `SortableGroup` names one group's rows; `useSortableRow` and `DragHandle` make a table row
| one of them. `SortableList` is a short list of its own, as a dialog shows one.
*/

export type SortableItem = { id: string; label: string; extra?: ReactNode };

type SortableData = { sortable?: { containerId?: UniqueIdentifier } } | undefined;

const groupOf = (data: unknown): UniqueIdentifier | undefined => (data as SortableData)?.sortable?.containerId;

/** Collisions only with the rows of the group the row being dragged belongs to. */
const sameGroup: CollisionDetection = (args) => {
    const group = groupOf(args.active.data.current);

    return closestCenter({ ...args, droppableContainers: args.droppableContainers.filter((container) => groupOf(container.data.current) === group) });
};

/** The arrow keys move a row among its own group only - never onto a row of another parent. */
const sameGroupKeys: KeyboardCoordinateGetter = (event, args) => {
    const { context } = args;
    const group = groupOf(context.active?.data.current);
    const containers = context.droppableContainers;
    const own = {
        getEnabled: () => containers.getEnabled().filter((container) => groupOf(container.data.current) === group),
        get: (id: UniqueIdentifier) => containers.get(id),
    } as unknown as typeof containers;

    return sortableKeyboardCoordinates(event, { ...args, context: { ...context, droppableContainers: own } });
};

export function SortableRows({
    groups,
    labelOf,
    onMove,
    children,
}: {
    /** Each group's ids, in their order now. */
    groups: Record<string, string[]>;
    labelOf: (id: string) => string;
    /** A group's ids in the order dropped. */
    onMove: (group: string, ids: string[]) => void;
    children: ReactNode;
}) {
    const t = useTranslator();
    const dndId = useId();
    // dnd-kit reports the row over its own place the moment it is picked up; said then, it would talk
    // over "picked up", so that first report is not said (as the address formats found).
    const justPicked = useRef(false);
    const sensors = useSensors(useSensor(MouseSensor, {}), useSensor(TouchSensor, {}), useSensor(KeyboardSensor, { coordinateGetter: sameGroupKeys }));
    const groupIds = (id: UniqueIdentifier) => Object.values(groups).find((ids) => ids.includes(String(id))) ?? [];
    const label = (id: UniqueIdentifier) => labelOf(String(id));
    const position = (id: UniqueIdentifier) => groupIds(id).indexOf(String(id)) + 1;
    const total = (id: UniqueIdentifier) => groupIds(id).length;

    function onDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        const group = groupOf(active.data.current);

        if (over === null || active.id === over.id || group === undefined || groupOf(over.data.current) !== group) {
            return;
        }

        const ids = groups[String(group)] ?? [];
        const from = ids.indexOf(String(active.id));
        const to = ids.indexOf(String(over.id));

        if (from >= 0 && to >= 0) {
            onMove(String(group), arrayMove(ids, from, to));
        }
    }

    return (
        <DndContext
            id={dndId}
            sensors={sensors}
            collisionDetection={sameGroup}
            modifiers={[restrictToVerticalAxis]}
            onDragEnd={onDragEnd}
            accessibility={{
                screenReaderInstructions: { draggable: t('catalog::admin.drag.instructions') },
                announcements: {
                    onDragStart: ({ active }) => {
                        justPicked.current = true;

                        return t('catalog::admin.drag.picked', { item: label(active.id) });
                    },
                    onDragOver: ({ active, over }) => {
                        const first = justPicked.current;
                        justPicked.current = false;

                        return over === null || first ? undefined : t('catalog::admin.drag.moved', { item: label(active.id), position: position(over.id), total: total(active.id) });
                    },
                    onDragEnd: ({ active, over }) =>
                        over === null ? t('catalog::admin.drag.cancelled', { item: label(active.id) }) : t('catalog::admin.drag.dropped', { item: label(active.id), position: position(over.id), total: total(active.id) }),
                    onDragCancel: ({ active }) => t('catalog::admin.drag.cancelled', { item: label(active.id) }),
                },
            }}
        >
            {children}
        </DndContext>
    );
}

/** One group's rows, in their order. It draws nothing: its rows may sit anywhere under it. */
export function SortableGroup({ id, ids, children }: { id: string; ids: string[]; children: ReactNode }) {
    return (
        <SortableContext id={id} items={ids} strategy={verticalListSortingStrategy}>
            {children}
        </SortableContext>
    );
}

export type RowHandle = ReturnType<typeof useSortableRow>['handle'];

/** A row of a group: what its element takes, and what its handle takes. */
export function useSortableRow(id: string, disabled = false) {
    const t = useTranslator();
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
        id,
        disabled,
        // dnd-kit names the handle's role "sortable", in English, on every page.
        attributes: { roleDescription: t('catalog::admin.drag.role') },
    });
    const style: CSSProperties = { transform: CSS.Transform.toString(transform), transition };

    return {
        ref: setNodeRef,
        style,
        dragging: isDragging,
        handle: { ref: setActivatorNodeRef, attributes, listeners },
    };
}

/**
 * A row's drag handle. For a reader who may not change the order it stays in sight, out of reach,
 * with the reason (P2).
 */
export function DragHandle({ handle, label, reason, test }: { handle: RowHandle; label: string; reason?: string; test?: string }) {
    const t = useTranslator();
    const name = t('catalog::admin.drag.reorder', { item: label });

    if (reason !== undefined) {
        return (
            <ActionButton type="button" variant="ghost" size="icon-sm" aria-label={name} disabledReason={reason} className="text-muted-foreground" data-test={test}>
                <GripVertical aria-hidden="true" />
            </ActionButton>
        );
    }

    return (
        <Button
            ref={handle.ref}
            {...handle.attributes}
            {...handle.listeners}
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={name}
            title={name}
            className="cursor-grab text-muted-foreground hover:bg-transparent active:cursor-grabbing"
            data-test={test}
        >
            <GripVertical aria-hidden="true" />
        </Button>
    );
}

/**
 * Move to Top and Move to Bottom, on a row's ⋯ menu (amendment 16(d)): out of reach where the row
 * already is.
 */
export function MoveItems({ first, last, onTop, onBottom }: { first: boolean; last: boolean; onTop: () => void; onBottom: () => void }) {
    const t = useTranslator();

    return (
        <>
            <DropdownMenuItem disabled={first} onSelect={onTop} data-test="move-top">
                {t('catalog::admin.order.top')}
            </DropdownMenuItem>
            <DropdownMenuItem disabled={last} onSelect={onBottom} data-test="move-bottom">
                {t('catalog::admin.order.bottom')}
            </DropdownMenuItem>
        </>
    );
}

/**
 * A list's order, saved the moment it changes (amendment 16(d), P29): dropped, or one row moved to
 * the top or the bottom from its ⋯ menu. The page shows the order just sent until the answer
 * arrives, so a row stays where it was dropped; a refused order comes back with the page's data,
 * and the list follows it. Move to Top sends that one id - every other keeps its order after it,
 * whatever someone else did meanwhile; Move to Bottom sends every other one first.
 *
 * @param ids the list's ids in the server's order
 * @param url where the order is posted, as `ids`
 */
export function useSavedOrder(ids: string[], url: string) {
    const [sent, setSent] = useState<string[] | null>(null);
    const order = sent ?? ids;

    function save(next: string[], post: string[]) {
        setSent(next);
        router.post(url, { ids: post }, { preserveScroll: true, onFinish: () => setSent(null) });
    }

    return {
        order,
        move: (next: string[]) => save(next, next),
        toTop: (id: string) => save([id, ...order.filter((each) => each !== id)], [id]),
        toBottom: (id: string) => {
            const next = [...order.filter((each) => each !== id), id];
            save(next, next);
        },
    };
}

export function SortableList({ items, onChange, testPrefix = 'sortable' }: { items: SortableItem[]; onChange: (ids: string[]) => void; testPrefix?: string }) {
    const ids = items.map((item) => item.id);

    return (
        <SortableRows groups={{ [testPrefix]: ids }} labelOf={(id) => items.find((item) => item.id === id)?.label ?? ''} onMove={(_, next) => onChange(next)}>
            <SortableGroup id={testPrefix} ids={ids}>
                <div role="list" className="grid gap-2">
                    {items.map((item, index) => (
                        <Row key={item.id} item={item} index={index} testPrefix={testPrefix} />
                    ))}
                </div>
            </SortableGroup>
        </SortableRows>
    );
}

function Row({ item, index, testPrefix }: { item: SortableItem; index: number; testPrefix: string }) {
    const row = useSortableRow(item.id);

    return (
        <div
            ref={row.ref}
            role="listitem"
            data-test={`${testPrefix}-${index}`}
            data-dragging={row.dragging || undefined}
            className="material-small relative z-0 flex items-center gap-2 px-2 py-1.5 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80"
            style={row.style}
        >
            <DragHandle handle={row.handle} label={item.label} test={`${testPrefix}-drag-${index}`} />
            <span className="text-copy-14 text-ink">{item.label}</span>
            {item.extra ? <span className="ms-auto">{item.extra}</span> : null}
        </div>
    );
}
