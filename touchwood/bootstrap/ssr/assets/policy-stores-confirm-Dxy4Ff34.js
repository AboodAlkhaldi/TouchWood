import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { c as FieldSet, o as FieldLabel, r as FieldDescription, s as FieldLegend, t as Field } from "./field-Ce02Vx8L.js";
import { t as Note } from "./Note-BdXDWF9_.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { t as Checkbox } from "./checkbox-sLnHqNPv.js";
import { useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/policy-stores-confirm.tsx
function PolicyStoresConfirm({ title, description, label, legend, stores, others, notYours, pickReason, summary, busy, onConfirm, onClose, returnFocusTo, test }) {
	const [ticked, setTicked] = useState(() => stores.map((store) => store.id));
	const said = ticked.length === 0 ? null : summary(ticked);
	return /* @__PURE__ */ jsxs(PanelDialog, {
		destructive: true,
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		returnFocusTo,
		title,
		description,
		busy,
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			variant: "destructive",
			loading: busy,
			disabledReason: ticked.length === 0 ? pickReason : void 0,
			onClick: () => onConfirm(ticked),
			"data-test": test,
			children: label
		}),
		children: [/* @__PURE__ */ jsxs(FieldSet, {
			className: "gap-2",
			children: [/* @__PURE__ */ jsx(FieldLegend, {
				variant: "label",
				className: "text-label-14 text-ink",
				children: legend
			}), /* @__PURE__ */ jsxs("div", {
				className: "grid gap-2",
				"data-test": `${test}-stores`,
				children: [stores.map((store) => /* @__PURE__ */ jsxs(Field, {
					orientation: "horizontal",
					className: "w-auto",
					children: [/* @__PURE__ */ jsx(Checkbox, {
						id: `${test}-${store.code}`,
						checked: ticked.includes(store.id),
						onCheckedChange: (on) => setTicked((was) => on === true ? [...was, store.id] : was.filter((id) => id !== store.id)),
						"data-test": `${test}-store-${store.code}`
					}), /* @__PURE__ */ jsx(FieldLabel, {
						htmlFor: `${test}-${store.code}`,
						className: "font-normal",
						children: store.name
					})]
				}, store.id)), others.map((name) => /* @__PURE__ */ jsxs(Field, {
					orientation: "horizontal",
					className: "w-auto",
					"data-disabled": true,
					children: [/* @__PURE__ */ jsx(Checkbox, {
						id: `${test}-other-${name}`,
						checked: false,
						disabled: true,
						"aria-describedby": `${test}-other-${name}-why`,
						"data-test": `${test}-other`
					}), /* @__PURE__ */ jsxs("div", {
						className: "grid gap-0.5",
						children: [/* @__PURE__ */ jsx(FieldLabel, {
							htmlFor: `${test}-other-${name}`,
							className: "font-normal",
							children: name
						}), /* @__PURE__ */ jsx(FieldDescription, {
							id: `${test}-other-${name}-why`,
							children: notYours
						})]
					})]
				}, name))]
			})]
		}), said === null ? null : /* @__PURE__ */ jsx(Note, {
			size: "small",
			"data-test": `${test}-summary`,
			children: said
		})]
	});
}
export { PolicyStoresConfirm };
