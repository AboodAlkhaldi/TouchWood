import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as DialogError } from "./FormError-Bt6EoBN2.js";
import { a as DialogFooter, i as DialogDescription, o as DialogHeader, r as DialogContent, s as DialogTitle, t as Dialog } from "./dialog-C7NRbZ1B.js";
import { t as useReturnFocus } from "./use-return-focus-Dv6FFK6A.js";
import { a as AlertDialogFooter, i as AlertDialogDescription, n as AlertDialogCancel, o as AlertDialogHeader, r as AlertDialogContent, s as AlertDialogTitle, t as AlertDialog } from "./alert-dialog-DLsLjvq8.js";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/PanelDialog.tsx
function PanelDialog({ open, onOpenChange, destructive = false, wide = false, title, description, busy, confirm, children, returnFocusTo, cancelLabel }) {
	const t = useTranslator();
	const returnFocus = useReturnFocus(open, returnFocusTo);
	const change = (next) => busy && !next ? void 0 : onOpenChange(next);
	const dialogWidth = wide ? "sm:max-w-2xl" : "sm:max-w-md";
	const alertWidth = wide ? "data-[size=default]:sm:max-w-2xl" : "data-[size=default]:sm:max-w-md";
	const body = /* @__PURE__ */ jsxs("div", {
		className: "grid max-h-[70vh] gap-4 overflow-y-auto p-6",
		children: [
			destructive ? /* @__PURE__ */ jsxs(AlertDialogHeader, { children: [/* @__PURE__ */ jsx(AlertDialogTitle, {
				className: "text-heading-20 wrap-anywhere text-ink",
				children: title
			}), /* @__PURE__ */ jsx(AlertDialogDescription, {
				className: "text-copy-14 text-ink-muted",
				children: description
			})] }) : /* @__PURE__ */ jsxs(DialogHeader, { children: [/* @__PURE__ */ jsx(DialogTitle, {
				className: "text-heading-20 wrap-anywhere text-ink",
				children: title
			}), /* @__PURE__ */ jsx(DialogDescription, {
				className: "text-copy-14 text-ink-muted",
				children: description
			})] }),
			/* @__PURE__ */ jsx(DialogError, { open }),
			children
		]
	});
	return destructive ? /* @__PURE__ */ jsx(AlertDialog, {
		open,
		onOpenChange: change,
		children: /* @__PURE__ */ jsxs(AlertDialogContent, {
			onCloseAutoFocus: returnFocus,
			className: `material-modal gap-0 overflow-hidden border-0 p-0 text-start ${alertWidth}`,
			children: [body, /* @__PURE__ */ jsxs(AlertDialogFooter, {
				className: "border-t border-line bg-surface-sunken px-6 py-4",
				children: [/* @__PURE__ */ jsx(AlertDialogCancel, {
					disabled: busy,
					"data-test": "modal-cancel",
					children: cancelLabel ?? t("ui.cancel")
				}), confirm]
			})]
		})
	}) : /* @__PURE__ */ jsx(Dialog, {
		open,
		onOpenChange: change,
		children: /* @__PURE__ */ jsxs(DialogContent, {
			showCloseButton: false,
			onCloseAutoFocus: returnFocus,
			className: `material-modal gap-0 overflow-hidden border-0 p-0 text-start ${dialogWidth}`,
			children: [body, /* @__PURE__ */ jsxs(DialogFooter, {
				className: "border-t border-line bg-surface-sunken px-6 py-4",
				children: [/* @__PURE__ */ jsx(Button, {
					type: "button",
					variant: "outline",
					disabled: busy,
					onClick: () => onOpenChange(false),
					"data-test": "modal-cancel",
					children: cancelLabel ?? t("ui.cancel")
				}), confirm]
			})]
		})
	});
}
export { PanelDialog as t };
