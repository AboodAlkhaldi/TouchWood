import { createContext, useContext, useId, useRef, type ComponentProps, type ReactNode } from 'react';
import { DndContext, KeyboardSensor, MouseSensor, TouchSensor, closestCenter, useSensor, useSensors, type DragEndEvent, type UniqueIdentifier } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { TableRow } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';

/*
| A short list put in order by dragging (catalog.md §4.4, P8): a store's menu among one parent's
| children, a product's variant attributes, a product's related products - and, laid out as a grid of tiles,
| a gallery. shadcn's `dashboard-01` drag handles on @dnd-kit, as the address formats' fields are
| (frontend.md §1.11) - by mouse, touch or keyboard, each move said aloud in the page's language. Only
| the handle picks an item up.
|
| A table's rows are dragged the same way (`SortableRows`, `SortableRow`, `RowHandle`): a product's
| variants, "the table, dragged into the variants' order" (§4.4 S9) - dashboard-01's own data table.
*/

/** `label` names it aloud; `content`, when given, is drawn in its place (a photo's tile). */
export type SortableItem = { id: string; label: string; content?: ReactNode; extra?: ReactNode };

/**
 * What a list and a table share: the sensors, what is said aloud, and the order sent when an item is
 * put down somewhere else.
 */
function useDragArea(items: { id: string; label: string }[], onChange: (ids: string[]) => void, vertical: boolean) {
    const t = useTranslator();
    const dndId = useId();
    // dnd-kit reports the row over its own place the moment it is picked up; said then, it would talk
    // over "picked up", so that first report is not said (as the address formats found).
    const justPicked = useRef(false);
    const sensors = useSensors(useSensor(MouseSensor, {}), useSensor(TouchSensor, {}), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
    const label = (id: UniqueIdentifier) => items.find((item) => item.id === id)?.label ?? '';
    const position = (id: UniqueIdentifier) => items.findIndex((item) => item.id === id) + 1;

    function onDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        if (over === null || active.id === over.id) {
            return;
        }

        const from = items.findIndex((item) => item.id === active.id);
        const to = items.findIndex((item) => item.id === over.id);

        if (from >= 0 && to >= 0) {
            onChange(arrayMove(items, from, to).map((item) => item.id));
        }
    }

    return {
        id: dndId,
        sensors,
        collisionDetection: closestCenter,
        modifiers: vertical ? [restrictToVerticalAxis] : [],
        onDragEnd,
        accessibility: {
            screenReaderInstructions: { draggable: t('catalog::admin.drag.instructions') },
            announcements: {
                onDragStart: ({ active }: { active: { id: UniqueIdentifier } }) => {
                    justPicked.current = true;

                    return t('catalog::admin.drag.picked', { item: label(active.id) });
                },
                onDragOver: ({ active, over }: { active: { id: UniqueIdentifier }; over: { id: UniqueIdentifier } | null }) => {
                    const first = justPicked.current;
                    justPicked.current = false;

                    return over === null || first ? undefined : t('catalog::admin.drag.moved', { item: label(active.id), position: position(over.id), total: items.length });
                },
                onDragEnd: ({ active, over }: { active: { id: UniqueIdentifier }; over: { id: UniqueIdentifier } | null }) =>
                    over === null ? t('catalog::admin.drag.cancelled', { item: label(active.id) }) : t('catalog::admin.drag.dropped', { item: label(active.id), position: position(over.id), total: items.length }),
                onDragCancel: ({ active }: { active: { id: UniqueIdentifier } }) => t('catalog::admin.drag.cancelled', { item: label(active.id) }),
            },
        },
    };
}

export function SortableList({
    items,
    onChange,
    testPrefix = 'sortable',
    layout = 'list',
    disabled = false,
}: {
    items: SortableItem[];
    onChange: (ids: string[]) => void;
    testPrefix?: string;
    layout?: 'list' | 'grid';
    /** While a change is being saved: nothing is picked up, so no order is sent from before it. */
    disabled?: boolean;
}) {
    return (
        <DndContext {...useDragArea(items, onChange, layout === 'list')}>
            <SortableContext items={items.map((item) => item.id)} strategy={layout === 'list' ? verticalListSortingStrategy : rectSortingStrategy}>
                <div role="list" className={layout === 'list' ? 'grid gap-2' : 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5'}>
                    {items.map((item, index) => (
                        <Row key={item.id} item={item} index={index} testPrefix={testPrefix} layout={layout} disabled={disabled} />
                    ))}
                </div>
            </SortableContext>
        </DndContext>
    );
}

function Row({ item, index, testPrefix, layout, disabled }: { item: SortableItem; index: number; testPrefix: string; layout: 'list' | 'grid'; disabled: boolean }) {
    const t = useTranslator();
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
        id: item.id,
        disabled,
        // dnd-kit names the handle's role "sortable", in English, on every page.
        attributes: { roleDescription: t('catalog::admin.drag.role') },
    });

    return (
        <div
            ref={setNodeRef}
            role="listitem"
            data-test={`${testPrefix}-${index}`}
            data-dragging={isDragging || undefined}
            className={
                layout === 'list'
                    ? 'material-small relative z-0 flex items-center gap-2 px-2 py-1.5 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80'
                    : 'material-small relative z-0 grid gap-2 p-2 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80'
            }
            style={{ transform: CSS.Transform.toString(transform), transition }}
        >
            <Button
                ref={setActivatorNodeRef}
                {...attributes}
                {...listeners}
                type="button"
                variant="ghost"
                size="icon-sm"
                disabled={disabled}
                aria-label={t('catalog::admin.drag.reorder', { item: item.label })}
                title={t('catalog::admin.drag.reorder', { item: item.label })}
                className="cursor-grab text-muted-foreground hover:bg-transparent active:cursor-grabbing"
                data-test={`${testPrefix}-drag-${index}`}
            >
                <GripVertical aria-hidden="true" />
            </Button>
            {item.content ?? <span className="text-copy-14 text-ink">{item.label}</span>}
            {item.extra ? <span className={layout === 'list' ? 'ms-auto' : undefined}>{item.extra}</span> : null}
        </div>
    );
}

/**
 * A table's rows dragged into their order. It goes around the whole table: dnd-kit's spoken words are
 * no table content.
 */
export function SortableRows({ items, onChange, children }: { items: { id: string; label: string }[]; onChange: (ids: string[]) => void; children: ReactNode }) {
    return (
        <DndContext {...useDragArea(items, onChange, true)}>
            <SortableContext items={items.map((item) => item.id)} strategy={verticalListSortingStrategy}>
                {children}
            </SortableContext>
        </DndContext>
    );
}

type RowDrag = { sortable: ReturnType<typeof useSortable>; label: string; disabled: boolean };

const RowDragContext = createContext<RowDrag | null>(null);

/** A row of a `SortableRows` table; its `RowHandle` picks it up. */
export function SortableRow({ id, label, disabled = false, style, ...props }: ComponentProps<'tr'> & { id: string; label: string; disabled?: boolean }) {
    const t = useTranslator();
    const sortable = useSortable({ id, disabled, attributes: { roleDescription: t('catalog::admin.drag.role') } });

    return (
        <RowDragContext.Provider value={{ sortable, label, disabled }}>
            <TableRow
                ref={sortable.setNodeRef}
                data-dragging={sortable.isDragging || undefined}
                style={{ ...style, transform: CSS.Transform.toString(sortable.transform), transition: sortable.transition }}
                {...props}
            />
        </RowDragContext.Provider>
    );
}

export function RowHandle({ testId }: { testId?: string }) {
    const t = useTranslator();
    const row = useContext(RowDragContext);

    if (row === null) {
        return null;
    }

    return (
        <Button
            ref={row.sortable.setActivatorNodeRef}
            {...row.sortable.attributes}
            {...row.sortable.listeners}
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled={row.disabled}
            aria-label={t('catalog::admin.drag.reorder', { item: row.label })}
            title={t('catalog::admin.drag.reorder', { item: row.label })}
            className="cursor-grab text-muted-foreground hover:bg-transparent active:cursor-grabbing"
            data-test={testId}
        >
            <GripVertical aria-hidden="true" />
        </Button>
    );
}
