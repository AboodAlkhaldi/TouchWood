import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { t as Label } from "./label-BuXzdX1o.js";
import { n as intlLocale } from "./digits-D5IxcRsf.js";
import { n as RadioGroupItem, t as RadioGroup } from "./radio-group-se2OA-ip.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { usePage } from "@inertiajs/react";
import { useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/scope-dialog.tsx
function ScopeDialog({ title, sentence, label, stores, choose, destructive, busy, onConfirm, onClose, returnFocusTo, test }) {
	const t = useTranslator();
	const [scope, setScope] = useState("all");
	const reached = scope === "all" ? stores : stores.filter((store) => store.id === scope);
	const { locale } = usePage().props;
	const list = new Intl.ListFormat(intlLocale(locale), { type: "conjunction" }).format(reached.map((store) => store.name));
	return /* @__PURE__ */ jsx(PanelDialog, {
		destructive,
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		returnFocusTo,
		title,
		description: sentence(list),
		busy,
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			variant: destructive ? "destructive" : "default",
			loading: busy,
			onClick: () => onConfirm(scope === "all" ? null : scope),
			"data-test": `${test}-confirm`,
			children: label
		}),
		children: choose && stores.length > 1 ? /* @__PURE__ */ jsx(RadioGroup, {
			value: scope,
			onValueChange: setScope,
			className: "grid gap-2",
			"aria-label": t("content::admin_campaigns.scope.label"),
			"data-test": `${test}-scope`,
			children: [{
				id: "all",
				name: t("content::admin_campaigns.scope.all")
			}, ...stores].map((store) => /* @__PURE__ */ jsxs("div", {
				className: "flex items-center gap-2",
				children: [/* @__PURE__ */ jsx(RadioGroupItem, {
					id: `${test}-scope-${store.id}`,
					value: store.id,
					"data-test": `${test}-scope-${store.id}`
				}), /* @__PURE__ */ jsx(Label, {
					htmlFor: `${test}-scope-${store.id}`,
					className: "font-normal",
					children: store.name
				})]
			}, store.id))
		}) : null
	});
}
export { ScopeDialog };
