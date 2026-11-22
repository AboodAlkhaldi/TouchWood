import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { o as FieldLabel, t as Field } from "./field-Ce02Vx8L.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { t as Checkbox } from "./checkbox-sLnHqNPv.js";
import { useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/translate-dialogs.tsx
/** "Replace the English text?", before a typed English box is replaced. */
function ReplaceDialog({ onReplace, onKeep }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: true,
		onOpenChange: (open) => open ? void 0 : onKeep(),
		title: t("content::admin_shared.translate.replace_title"),
		description: t("content::admin_shared.translate.replace_body"),
		busy: false,
		cancelLabel: t("content::admin_shared.translate.keep"),
		confirm: /* @__PURE__ */ jsx(Button, {
			type: "button",
			onClick: onReplace,
			"data-test": "replace-text",
			children: t("content::admin_shared.translate.replace")
		}),
		children: null
	});
}
/** The one required tick before marked English reaches shoppers (§1.3 #3), then the form's own button. */
function ReadTickDialog({ label, onSend, onClose }) {
	const t = useTranslator();
	const [ticked, setTicked] = useState(false);
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		title: t("content::admin_shared.translate.read_title"),
		description: t("content::admin_shared.translate.read_body"),
		busy: false,
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			type: "button",
			disabledReason: ticked ? void 0 : t("content::admin_shared.translate.read_tick"),
			onClick: onSend,
			"data-test": "read-confirm",
			children: label
		}),
		children: /* @__PURE__ */ jsxs(Field, {
			orientation: "horizontal",
			children: [/* @__PURE__ */ jsx(Checkbox, {
				id: "read-tick",
				checked: ticked,
				onCheckedChange: (on) => setTicked(on === true),
				"data-test": "read-tick"
			}), /* @__PURE__ */ jsx(FieldLabel, {
				htmlFor: "read-tick",
				className: "font-normal",
				children: t("content::admin_shared.translate.read_tick")
			})]
		})
	});
}
export { ReadTickDialog, ReplaceDialog };
