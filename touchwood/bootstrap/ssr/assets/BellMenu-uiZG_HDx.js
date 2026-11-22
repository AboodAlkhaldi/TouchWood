import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { n as PopoverAnchor, r as PopoverContent, t as Popover } from "./popover-BbvwzNo6.js";
import { t as readJson } from "./live-read-Dv7wC0si.js";
import { S as useMarkAll, Z as Skeleton, _ as ScrollArea, b as recheck, v as BellRows, x as tell, y as ask } from "../ssr.js";
import { Link } from "@inertiajs/react";
import { useEffect, useId, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/ops/BellMenu.tsx
function BellMenu({ anchor, open, onOpenChange, base, unread, words, manyStores }) {
	const [entries, setEntries] = useState(null);
	const [failed, setFailed] = useState(false);
	const [attempt, setAttempt] = useState(0);
	const heading = useRef(null);
	const headingId = useId();
	const { marking, failed: markFailed, markAll, clear } = useMarkAll(base, () => heading.current?.focus());
	useEffect(() => {
		if (!open) return;
		const controller = new AbortController();
		const order = ask();
		setEntries(null);
		setFailed(false);
		clear();
		readJson(base, {}, controller.signal).then((answer) => {
			if (controller.signal.aborted) return;
			setFailed(answer === null);
			if (answer !== null) {
				setEntries(answer.entries);
				tell(order, { unread: answer.unread });
			}
		});
		return () => controller.abort();
	}, [
		open,
		attempt,
		base
	]);
	return /* @__PURE__ */ jsxs(Popover, {
		open,
		onOpenChange,
		children: [/* @__PURE__ */ jsx(PopoverAnchor, { virtualRef: anchor }), /* @__PURE__ */ jsxs(PopoverContent, {
			align: "end",
			collisionPadding: 16,
			className: "grid w-[min(24rem,calc(100vw-2rem))] gap-2 p-2",
			"aria-labelledby": headingId,
			"data-test": "bell-menu",
			onInteractOutside: (event) => {
				if (anchor.current?.contains(event.detail.originalEvent.target)) event.preventDefault();
			},
			onCloseAutoFocus: (event) => {
				event.preventDefault();
				anchor.current?.focus();
			},
			children: [
				/* @__PURE__ */ jsxs("div", {
					className: "flex flex-wrap items-center justify-between gap-2 px-2 py-1",
					children: [/* @__PURE__ */ jsx("h2", {
						ref: heading,
						id: headingId,
						tabIndex: -1,
						className: "text-label-14 font-medium outline-none",
						children: words.title
					}), unread > 0 && entries !== null ? /* @__PURE__ */ jsx(ActionButton, {
						type: "button",
						variant: "ghost",
						size: "sm",
						loading: marking,
						onClick: markAll,
						"data-test": "bell-mark-all",
						children: words.mark_all
					}) : null]
				}),
				markFailed ? /* @__PURE__ */ jsx("p", {
					role: "alert",
					className: "px-2 text-copy-13 text-bad",
					"data-test": "bell-mark-failed",
					children: words.mark_failed
				}) : null,
				failed ? /* @__PURE__ */ jsxs("div", {
					className: "grid justify-items-start gap-2 px-2 py-4",
					role: "alert",
					"data-test": "bell-failed",
					children: [/* @__PURE__ */ jsx("p", {
						className: "text-copy-14",
						children: words.failed
					}), /* @__PURE__ */ jsx(Button, {
						type: "button",
						variant: "outline",
						size: "sm",
						onClick: () => setAttempt((count) => count + 1),
						"data-test": "bell-retry",
						children: words.retry
					})]
				}) : entries === null ? /* @__PURE__ */ jsxs("div", {
					className: "grid gap-2 p-2",
					"data-test": "bell-loading",
					children: [
						/* @__PURE__ */ jsx(Skeleton, { className: "h-14 w-full" }),
						/* @__PURE__ */ jsx(Skeleton, { className: "h-14 w-full" }),
						/* @__PURE__ */ jsx(Skeleton, { className: "h-14 w-full" })
					]
				}) : entries.length === 0 ? /* @__PURE__ */ jsx("p", {
					className: "px-2 py-4 text-copy-14 text-muted-foreground",
					"data-test": "bell-empty",
					children: words.empty
				}) : /* @__PURE__ */ jsx(ScrollArea, {
					className: "[&>[data-slot=scroll-area-viewport]]:max-h-[min(24rem,60vh)]",
					children: /* @__PURE__ */ jsx(BellRows, {
						entries,
						base,
						words,
						manyStores,
						onGo: () => onOpenChange(false)
					})
				}),
				/* @__PURE__ */ jsx(Button, {
					asChild: true,
					variant: "outline",
					size: "sm",
					className: "w-full",
					children: /* @__PURE__ */ jsx(Link, {
						href: base,
						onClick: () => onOpenChange(false),
						"data-test": "bell-view-all",
						children: words.view_all
					})
				})
			]
		})]
	});
}
export { BellMenu, recheck };
