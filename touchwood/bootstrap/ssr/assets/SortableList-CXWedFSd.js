import { t as __exportAll } from "./rolldown-runtime-D8jQpDG7.js";
import { a as DropdownMenuItem } from "./dropdown-menu-Bw9NuOcA.js";
import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { router } from "@inertiajs/react";
import { useId, useRef, useState } from "react";
import { GripVertical } from "lucide-react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
import { DndContext, KeyboardSensor, MouseSensor, TouchSensor, closestCenter, useSensor, useSensors } from "@dnd-kit/core";
import { restrictToVerticalAxis } from "@dnd-kit/modifiers";
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
// resources/js/pages/Catalog/Admin/SortableList.tsx
var SortableList_exports = /* @__PURE__ */ __exportAll({
	DragHandle: () => DragHandle,
	MoveItems: () => MoveItems,
	SortableGroup: () => SortableGroup,
	SortableGroups: () => SortableGroups,
	SortableList: () => SortableList,
	useSaveQueue: () => useSaveQueue,
	useSavedOrder: () => useSavedOrder,
	useSortableRow: () => useSortableRow
});
var containerOf = (data) => data?.sortable?.containerId;
/** Collisions only with the rows of the group the row being dragged belongs to. */
var sameGroup = (args) => {
	const group = containerOf(args.active.data.current);
	return closestCenter({
		...args,
		droppableContainers: args.droppableContainers.filter((container) => containerOf(container.data.current) === group)
	});
};
/** The arrow keys move a row among its own group only - never onto a row of another parent. */
var sameGroupKeys = (event, args) => {
	const { context } = args;
	const group = containerOf(context.active?.data.current);
	const containers = context.droppableContainers;
	const own = {
		getEnabled: () => containers.getEnabled().filter((container) => containerOf(container.data.current) === group),
		get: (id) => containers.get(id)
	};
	return sortableKeyboardCoordinates(event, {
		...args,
		context: {
			...context,
			droppableContainers: own
		}
	});
};
/** The group an id is in, with that group's ids in their order now. */
function groupHolding(groups, id) {
	const key = Object.keys(groups).find((each) => groups[each]?.includes(String(id)));
	return key === void 0 ? void 0 : [key, groups[key] ?? []];
}
/**
* What every list and table dragged into order shares: the sensors, a row kept among its own group,
* what is said aloud, and the order sent when a row is put down somewhere else.
*/
function useDragArea(groups, labelOf, onMove, vertical) {
	const t = useTranslator();
	const dndId = useId();
	const justPicked = useRef(false);
	const sensors = useSensors(useSensor(MouseSensor, {}), useSensor(TouchSensor, {}), useSensor(KeyboardSensor, { coordinateGetter: sameGroupKeys }));
	const label = (id) => labelOf(String(id));
	const groupIds = (id) => groupHolding(groups, id)?.[1] ?? [];
	const position = (id) => groupIds(id).indexOf(String(id)) + 1;
	const total = (id) => groupIds(id).length;
	function onDragEnd(event) {
		const { active, over } = event;
		const group = groupHolding(groups, active.id);
		if (over === null || active.id === over.id || group === void 0) return;
		const [key, ids] = group;
		const from = ids.indexOf(String(active.id));
		const to = ids.indexOf(String(over.id));
		if (from >= 0 && to >= 0) onMove(key, arrayMove(ids, from, to));
	}
	return {
		id: dndId,
		sensors,
		collisionDetection: sameGroup,
		modifiers: vertical ? [restrictToVerticalAxis] : [],
		onDragEnd,
		accessibility: {
			screenReaderInstructions: { draggable: t("catalog::admin.drag.instructions") },
			announcements: {
				onDragStart: ({ active }) => {
					justPicked.current = true;
					return t("catalog::admin.drag.picked", { item: label(active.id) });
				},
				onDragOver: ({ active, over }) => {
					const first = justPicked.current;
					justPicked.current = false;
					return over === null || first ? void 0 : t("catalog::admin.drag.moved", {
						item: label(active.id),
						position: position(over.id),
						total: total(active.id)
					});
				},
				onDragEnd: ({ active, over }) => over === null ? t("catalog::admin.drag.cancelled", { item: label(active.id) }) : t("catalog::admin.drag.dropped", {
					item: label(active.id),
					position: position(over.id),
					total: total(active.id)
				}),
				onDragCancel: ({ active }) => t("catalog::admin.drag.cancelled", { item: label(active.id) })
			}
		}
	};
}
/** One list's items as `useDragArea` takes them: a single group, named by its labels. */
function useOneGroup(items, onChange, vertical) {
	return useDragArea({ items: items.map((item) => item.id) }, (id) => items.find((item) => item.id === id)?.label ?? "", (_, ids) => onChange(ids), vertical);
}
function SortableList({ items, onChange, testPrefix = "sortable", layout = "list", disabled = false }) {
	return /* @__PURE__ */ jsx(DndContext, {
		...useOneGroup(items, onChange, layout === "list"),
		children: /* @__PURE__ */ jsx(SortableContext, {
			items: items.map((item) => item.id),
			strategy: layout === "list" ? verticalListSortingStrategy : rectSortingStrategy,
			children: /* @__PURE__ */ jsx("div", {
				role: "list",
				className: layout === "list" ? "grid gap-2" : "grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5",
				children: items.map((item, index) => /* @__PURE__ */ jsx(Row, {
					item,
					index,
					testPrefix,
					layout,
					disabled
				}, item.id))
			})
		})
	});
}
function Row({ item, index, testPrefix, layout, disabled }) {
	const row = useSortableRow(item.id, disabled);
	return /* @__PURE__ */ jsxs("div", {
		ref: row.ref,
		role: "listitem",
		"data-test": `${testPrefix}-${index}`,
		"data-dragging": row.dragging || void 0,
		className: layout === "list" ? "material-small relative z-0 flex items-center gap-2 px-2 py-1.5 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80" : "material-small relative z-0 grid gap-2 p-2 data-[dragging=true]:z-10 data-[dragging=true]:opacity-80",
		style: row.style,
		children: [
			/* @__PURE__ */ jsx(DragHandle, {
				handle: row.handle,
				label: item.label,
				disabled,
				test: `${testPrefix}-drag-${index}`
			}),
			item.content ?? /* @__PURE__ */ jsx("span", {
				className: "text-copy-14 text-ink",
				children: item.label
			}),
			item.extra ? /* @__PURE__ */ jsx("span", {
				className: layout === "list" ? "ms-auto" : void 0,
				children: item.extra
			}) : null
		]
	});
}
/**
* Lists whose rows sit in a table or a tree of their own, each in its group (`SortableGroup`): a
* list's rows, a product's variants, or each parent's children in the category tree. It goes around
* the whole table - dnd-kit's spoken words are no table content. A row is dropped only among its own
* group, and `onMove` is given that group's ids in the order dropped.
*/
function SortableGroups({ groups, labelOf, onMove, layout = "list", children }) {
	return /* @__PURE__ */ jsx(DndContext, {
		...useDragArea(groups, labelOf, onMove, layout === "list"),
		children
	});
}
/** One group's rows, in their order. It draws nothing: its rows may sit anywhere under it. */
function SortableGroup({ id, ids, layout = "list", children }) {
	return /* @__PURE__ */ jsx(SortableContext, {
		id,
		items: ids,
		strategy: layout === "list" ? verticalListSortingStrategy : rectSortingStrategy,
		children
	});
}
/** A row of a group: what its element takes, and what its handle takes. */
function useSortableRow(id, disabled = false) {
	const t = useTranslator();
	const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
		id,
		disabled,
		attributes: { roleDescription: t("catalog::admin.drag.role") }
	});
	return {
		ref: setNodeRef,
		style: {
			transform: CSS.Transform.toString(transform),
			transition
		},
		dragging: isDragging,
		handle: {
			ref: setActivatorNodeRef,
			attributes,
			listeners
		}
	};
}
/**
* A row's drag handle. For a reader who may not change the order it stays in sight, out of reach,
* with the reason (P2); while a change is being saved (`disabled`), it picks nothing up.
*/
function DragHandle({ handle, label, reason, disabled = false, test }) {
	const name = useTranslator()("catalog::admin.drag.reorder", { item: label });
	if (reason !== void 0) return /* @__PURE__ */ jsx(ActionButton, {
		type: "button",
		variant: "ghost",
		size: "icon-sm",
		"aria-label": name,
		disabledReason: reason,
		className: "text-muted-foreground",
		"data-test": test,
		children: /* @__PURE__ */ jsx(GripVertical, { "aria-hidden": "true" })
	});
	return /* @__PURE__ */ jsx(Button, {
		ref: handle.ref,
		...handle.attributes,
		...handle.listeners,
		type: "button",
		variant: "ghost",
		size: "icon-sm",
		disabled,
		"aria-label": name,
		title: name,
		className: "cursor-grab text-muted-foreground hover:bg-transparent active:cursor-grabbing",
		"data-test": test,
		children: /* @__PURE__ */ jsx(GripVertical, { "aria-hidden": "true" })
	});
}
/**
* Move to Top and Move to Bottom, on a row's ⋯ menu (amendment 16(d)): out of reach where the row
* already is, with that reason written under it, as every item that cannot be used.
*/
function MoveItems({ first, last, onTop, onBottom, testPrefix = "move" }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsxs(Fragment$1, { children: [/* @__PURE__ */ jsx(MoveItem, {
		label: t("catalog::admin.order.top"),
		reason: first ? t("catalog::admin.order.at_top") : null,
		onSelect: onTop,
		test: `${testPrefix}-top`
	}), /* @__PURE__ */ jsx(MoveItem, {
		label: t("catalog::admin.order.bottom"),
		reason: last ? t("catalog::admin.order.at_bottom") : null,
		onSelect: onBottom,
		test: `${testPrefix}-bottom`
	})] });
}
function MoveItem({ label, reason, onSelect, test }) {
	return /* @__PURE__ */ jsx(DropdownMenuItem, {
		"aria-disabled": reason !== null || void 0,
		className: reason !== null ? "cursor-not-allowed" : void 0,
		onSelect: (event) => {
			if (reason !== null) {
				event.preventDefault();
				return;
			}
			onSelect();
		},
		"data-test": test,
		children: /* @__PURE__ */ jsxs("span", {
			className: "grid gap-0.5",
			children: [/* @__PURE__ */ jsx("span", {
				className: reason !== null ? "opacity-60" : void 0,
				children: label
			}), reason !== null ? /* @__PURE__ */ jsx("span", {
				className: "text-copy-13 text-ink-muted",
				children: reason
			}) : null]
		})
	});
}
/**
* An order's saves, sent one at a time: each when the one before it is answered, so they reach the
* server in the order they were made and a later one is never undone by an earlier one arriving
* after it. `idle` runs when the last is answered - the page then shows the server's order again.
*/
function useSaveQueue(idle) {
	const waiting = useRef([]);
	const sending = useRef(false);
	function next() {
		const send = waiting.current.shift();
		if (send === void 0) {
			sending.current = false;
			idle();
			return;
		}
		sending.current = true;
		send(next);
	}
	return (send) => {
		waiting.current.push(send);
		if (!sending.current) next();
	};
}
/**
* A list's order, saved the moment it changes (amendment 16(d), P29): dropped, or one row moved to
* the top or the bottom from its ⋯ menu. The page shows the order last made until every save is
* answered, so a row stays where it was dropped; a refused order comes back with the page's data,
* and the list follows it. Move to Top sends that one id - every other keeps its order after it,
* whatever someone else did meanwhile; Move to Bottom sends every other one first.
*
* @param ids the list's ids in the server's order
* @param url where the order is posted
* @param field the field it is posted as
*/
function useSavedOrder(ids, url, field = "ids") {
	const [sent, setSent] = useState(null);
	const queue = useSaveQueue(() => setSent(null));
	const order = sent ?? ids;
	function save(next, post) {
		setSent(next);
		queue((answered) => router.post(url, { [field]: post }, {
			async: true,
			preserveScroll: true,
			onFinish: answered
		}));
	}
	return {
		order,
		move: (next) => save(next, next),
		toTop: (id) => save([id, ...order.filter((each) => each !== id)], [id]),
		toBottom: (id) => {
			const next = [...order.filter((each) => each !== id), id];
			save(next, next);
		}
	};
}
export { SortableList as a, useSavedOrder as c, SortableGroups as i, useSortableRow as l, MoveItems as n, SortableList_exports as o, SortableGroup as r, useSaveQueue as s, DragHandle as t };
