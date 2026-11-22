import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { jsx } from "react/jsx-runtime";
// resources/js/components/content/schedule-confirm.tsx
function ScheduleConfirm({ title, description, label, busy, onConfirm, onClose, returnFocusTo, test }) {
	return /* @__PURE__ */ jsx(PanelDialog, {
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
			onClick: onConfirm,
			"data-test": test,
			children: label
		}),
		children: null
	});
}
export { ScheduleConfirm };
