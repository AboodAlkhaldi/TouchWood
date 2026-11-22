import { a as DropdownMenuItem } from "./dropdown-menu-Bw9NuOcA.js";
import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as Spinner } from "./spinner-C8hxoQvt.js";
import { forwardRef } from "react";
import { MoreHorizontal } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/RowMenu.tsx
/** A row's ⋯ button: its name says whose actions, and a spinner stands in while one is on its way. */
var MoreButton = forwardRef(function MoreButton({ name, busy = false, ...rest }, ref) {
	const t = useTranslator();
	return /* @__PURE__ */ jsx(Button, {
		ref,
		type: "button",
		variant: "ghost",
		size: "icon-sm",
		"aria-label": `${t("ui.more_actions")}: ${name}`,
		"aria-busy": busy || void 0,
		title: t("ui.more_actions"),
		...rest,
		children: busy ? /* @__PURE__ */ jsx(Spinner, {
			"aria-hidden": "true",
			role: void 0,
			"aria-label": void 0
		}) : /* @__PURE__ */ jsx(MoreHorizontal, { "aria-hidden": "true" })
	});
});
/**
* A ⋯ item that may be out of reach: drawn, its reason written under it (Catalog's Guarded). Destructive
* ones in red, last after a separator.
*/
function Guarded({ label, reason, onSelect, test, destructive = false }) {
	const off = reason !== void 0 && reason !== null;
	return /* @__PURE__ */ jsx(DropdownMenuItem, {
		variant: destructive ? "destructive" : "default",
		"aria-disabled": off || void 0,
		className: off ? "cursor-not-allowed" : void 0,
		onSelect: (event) => {
			if (off) {
				event.preventDefault();
				return;
			}
			onSelect();
		},
		"data-test": test,
		children: /* @__PURE__ */ jsxs("span", {
			className: "grid gap-0.5",
			children: [/* @__PURE__ */ jsx("span", {
				className: off ? "opacity-60" : void 0,
				children: label
			}), off ? /* @__PURE__ */ jsx("span", {
				className: "text-copy-13 text-ink-muted",
				children: reason
			}) : null]
		})
	});
}
export { MoreButton as n, Guarded as t };
