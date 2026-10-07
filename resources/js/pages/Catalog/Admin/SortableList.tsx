import { useId, useRef, type ReactNode } from 'react';
import { DndContext, KeyboardSensor, MouseSensor, TouchSensor, closestCenter, useSensor, useSensors, type DragEndEvent, type UniqueIdentifier } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';

/*
| A short list put in order by dragging (catalog.md §4.4, P8): a store's menu among one parent's
| children, a variation's attributes, a product's related products - and, laid out as a grid of tiles,
| a gallery. shadcn's `dashboard-01` drag handles on @dnd-kit, as the address formats' fields are
| (frontend.md §1.11) - by mouse, touch or keyboard, each move said aloud in the page's language. Only
| the handle picks an item up.
*/

/** `label` names it aloud; `content`, when given, is drawn in its place (a photo's tile). */
export type SortableItem = { id: string; label: string; content?: ReactNode; extra?: ReactNode };

export function SortableList({
    items,
    onChange,
    testPrefix = 'sortable',
    layout = 'list',
}: {
    items: SortableItem[];
    onChange: (ids: string[]) => void;
    testPrefix?: string;
    layout?: 'list' | 'grid';
}) {
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

    return (
        <DndContext
            id={dndId}
            sensors={sensors}
            collisionDetection={closestCenter}
            modifiers={layout === 'list' ? [restrictToVerticalAxis] : []}
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

                        return over === null || first ? undefined : t('catalog::admin.drag.moved', { item: label(active.id), position: position(over.id), total: items.length });
                    },
                    onDragEnd: ({ active, over }) =>
                        over === null ? t('catalog::admin.drag.cancelled', { item: label(active.id) }) : t('catalog::admin.drag.dropped', { item: label(active.id), position: position(over.id), total: items.length }),
                    onDragCancel: ({ active }) => t('catalog::admin.drag.cancelled', { item: label(active.id) }),
                },
            }}
        >
            <SortableContext items={items.map((item) => item.id)} strategy={layout === 'list' ? verticalListSortingStrategy : rectSortingStrategy}>
                <div role="list" className={layout === 'list' ? 'grid gap-2' : 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5'}>
                    {items.map((item, index) => (
                        <Row key={item.id} item={item} index={index} testPrefix={testPrefix} layout={layout} />
                    ))}
                </div>
            </SortableContext>
        </DndContext>
    );
}

function Row({ item, index, testPrefix, layout }: { item: SortableItem; index: number; testPrefix: string; layout: 'list' | 'grid' }) {
    const t = useTranslator();
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
        id: item.id,
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
