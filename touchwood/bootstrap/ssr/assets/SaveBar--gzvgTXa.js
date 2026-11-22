import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { n as intlLocale, t as figure } from "./digits-D5IxcRsf.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { router, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
// resources/js/lib/visits.ts
function keepsBoxes(visit) {
	return visit.method !== "get" || visit.only.length > 0 && visit.preserveState !== false;
}
// resources/js/lib/leave-guard.ts
/** The fields Inertia adds to a visit of its own, which a new visit makes afresh. */
var OWN = [
	"id",
	"url",
	"cancelled",
	"completed",
	"interrupted"
];
function guardHub() {
	return {
		leaving: false,
		asking: null,
		guards: /* @__PURE__ */ new Set()
	};
}
/** How many of the page's guards hold changes now: the places a visit would lose them from. */
function changedPlaces(hub) {
	let count = 0;
	for (const dirty of hub.guards) count += dirty() ? 1 : 0;
	return count;
}
function leaveGuard(options) {
	const hub = options.hub ?? guardHub();
	const dirty = () => options.dirty();
	hub.guards.add(dirty);
	return {
		before: (visit) => {
			if (visit.prefetch || hub.leaving || !options.dirty() || keepsBoxes(visit) || options.keeps(visit)) return true;
			if (hub.asking === visit.id && visit.id !== void 0) return false;
			hub.asking = visit.id;
			options.hold(visit, Math.max(1, changedPlaces(hub)));
			return false;
		},
		leave: (visit) => {
			const asked = {};
			for (const [key, value] of Object.entries(visit)) if (!OWN.includes(key)) asked[key] = value;
			const finish = typeof visit.onFinish === "function" ? visit.onFinish : null;
			hub.leaving = true;
			hub.asking = null;
			options.visit(visit.url, {
				...asked,
				onFinish: (...args) => {
					hub.leaving = false;
					finish?.(...args);
				}
			});
		},
		leaving: () => hub.leaving,
		attach: () => {
			hub.guards.add(dirty);
			return () => {
				hub.guards.delete(dirty);
			};
		}
	};
}
// resources/js/components/SaveBar.tsx
function SaveBar({ id = "save-bar", count, message, saveLabel, saving, onSave, onCancel }) {
	const t = useTranslator();
	const shown = count > 0;
	return /* @__PURE__ */ jsxs(Fragment$1, { children: [/* @__PURE__ */ jsx("p", {
		className: "sr-only",
		"aria-live": "polite",
		"data-test": "save-bar-live",
		children: shown ? message : ""
	}), shown ? /* @__PURE__ */ jsxs("div", {
		role: "region",
		"aria-labelledby": `${id}-message`,
		className: "material-menu sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 px-4 py-3",
		"data-test": "save-bar",
		children: [/* @__PURE__ */ jsx("p", {
			id: `${id}-message`,
			className: "tw-figure text-label-14 text-ink",
			children: message
		}), /* @__PURE__ */ jsxs("div", {
			className: "flex flex-wrap items-center gap-2",
			children: [/* @__PURE__ */ jsx(Button, {
				type: "button",
				variant: "outline",
				disabled: saving,
				onClick: onCancel,
				"data-test": "save-bar-cancel",
				children: t("ui.cancel")
			}), /* @__PURE__ */ jsx(ActionButton, {
				type: "button",
				loading: saving,
				onClick: onSave,
				"data-test": "save-bar-save",
				children: saveLabel
			})]
		})]
	}) : null] });
}
/**
* A box typed with another value than the one saved (§4.3.1): a dot and "Changed", in words as well as
* colour, said with the box (give its id to the box's aria-describedby).
*/
function ChangedMark({ id }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsxs("span", {
		id,
		className: "inline-flex items-center gap-1 text-label-12 text-ink-muted",
		"data-test": "changed",
		children: [/* @__PURE__ */ jsx("span", {
			"aria-hidden": "true",
			className: "size-1.5 rounded-full bg-info"
		}), t("ui.changed")]
	});
}
/**
* Asks before leaving with changed boxes (pricing.md §4.3.1): a link, a filter, the search or the store
* filter - any Inertia visit that would replace the page - is held, and `PanelDialog` asks "Leave
* Without Saving" with **Leave Page** and **Stay**; leaving makes the same visit again, its options and
* callbacks kept. A prefetch is never held: it replaces nothing. Closing or
* reloading the window asks through the browser's own question, the only one it allows.
*
* *Not covered:* the browser's Back button. Inertia restores the page before any event a page could
* cancel (its `popstate` handler fires no `before`), so going back loses the boxes without asking.
*
* @param keeps  a visit that keeps the boxes, beside `keepsBoxes` - the page's own judgement
*/
/** The guards of the page shown share one question (lib/leave-guard.ts). */
var PAGE_GUARDS = guardHub();
function useLeaveGuard(dirty, words, keeps = () => false) {
	const t = useTranslator();
	const locale = usePage().props.locale;
	const [held, setHeld] = useState(null);
	const latest = useRef({
		dirty,
		keeps
	});
	latest.current = {
		dirty,
		keeps
	};
	const guard = useRef(null);
	guard.current ??= leaveGuard({
		dirty: () => latest.current.dirty,
		keeps: (visit) => latest.current.keeps(visit),
		hold: (visit, places) => setHeld({
			visit,
			places
		}),
		visit: (url, options) => router.visit(url, options),
		hub: PAGE_GUARDS
	});
	useEffect(() => {
		const detach = guard.current?.attach();
		const stop = router.on("before", (event) => guard.current?.before(event.detail.visit) === false ? false : void 0);
		const unload = (event) => {
			if (latest.current.dirty && guard.current?.leaving() !== true) {
				event.preventDefault();
				event.returnValue = "";
			}
		};
		window.addEventListener("beforeunload", unload);
		return () => {
			detach?.();
			stop();
			window.removeEventListener("beforeunload", unload);
		};
	}, []);
	const places = held?.places ?? 1;
	const description = places > 1 ? t(`ui.leave.places.${new Intl.PluralRules(intlLocale(locale)).select(places)}`, { count: figure(locale, places) }) : words.description;
	return /* @__PURE__ */ jsx(LeaveDialog, {
		visit: held?.visit ?? null,
		description,
		onStay: () => setHeld(null),
		onLeave: (visit) => {
			setHeld(null);
			guard.current?.leave(visit);
		}
	});
}
function LeaveDialog({ visit, description, onStay, onLeave }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: visit !== null,
		onOpenChange: (open) => open ? void 0 : onStay(),
		destructive: true,
		title: t("ui.leave.title"),
		description,
		busy: false,
		cancelLabel: t("ui.leave.stay"),
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			type: "button",
			variant: "destructive",
			onClick: () => visit === null ? void 0 : onLeave(visit),
			"data-test": "leave-page",
			children: t("ui.leave.confirm")
		}),
		children: null
	});
}
export { keepsBoxes as i, SaveBar as n, useLeaveGuard as r, ChangedMark as t };
