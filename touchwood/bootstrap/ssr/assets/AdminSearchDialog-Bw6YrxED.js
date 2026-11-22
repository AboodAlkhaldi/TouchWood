import { n as useTranslator } from "./t-Da8CTZFi.js";
import { i as DialogDescription, o as DialogHeader, r as DialogContent, s as DialogTitle, t as Dialog } from "./dialog-C7NRbZ1B.js";
import { a as CommandItem, i as CommandInput, n as CommandEmpty, o as CommandList, r as CommandGroup, t as Command$1 } from "./command-D8WAkiWr.js";
import { t as useReturnFocus } from "./use-return-focus-Dv6FFK6A.js";
import { it as MenuIcon } from "../ssr.js";
import { router, usePage } from "@inertiajs/react";
import { jsx, jsxs } from "react/jsx-runtime";
import { defaultFilter } from "cmdk";
// resources/js/components/AdminSearchDialog.tsx
function AdminSearchDialog({ open, onOpenChange }) {
	const { menu } = usePage().props;
	const t = useTranslator();
	const returnFocus = useReturnFocus(open);
	const title = t("admin.search.title");
	return /* @__PURE__ */ jsx(Dialog, {
		open,
		onOpenChange,
		children: /* @__PURE__ */ jsxs(DialogContent, {
			className: "overflow-hidden p-0",
			showCloseButton: false,
			onCloseAutoFocus: returnFocus,
			children: [/* @__PURE__ */ jsxs(DialogHeader, {
				className: "sr-only",
				children: [/* @__PURE__ */ jsx(DialogTitle, { children: title }), /* @__PURE__ */ jsx(DialogDescription, { children: t("admin.search.button") })]
			}), /* @__PURE__ */ jsxs(Command$1, {
				label: title,
				filter: (_value, search, keywords) => defaultFilter((keywords ?? []).join(" "), search),
				"data-test": "admin-search-command",
				className: "**:data-[slot=command-input-wrapper]:h-12 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]]:px-2 [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-input-wrapper]_svg]:h-5 [&_[cmdk-input-wrapper]_svg]:w-5 [&_[cmdk-input]]:h-12 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5",
				children: [/* @__PURE__ */ jsx(CommandInput, {
					placeholder: t("admin.search.button"),
					"data-test": "admin-search-input"
				}), /* @__PURE__ */ jsxs(CommandList, {
					label: title,
					children: [/* @__PURE__ */ jsx(CommandEmpty, { children: t("admin.search.empty") }), menu.map((group) => {
						const entries = group.entries.filter((entry) => !entry.comingSoon);
						if (entries.length === 0) return null;
						return /* @__PURE__ */ jsx(CommandGroup, {
							heading: group.label,
							children: entries.map((entry) => /* @__PURE__ */ jsxs(CommandItem, {
								value: `${entry.module}.${entry.key}`,
								keywords: [entry.label, group.label],
								"data-test": `admin-search-${entry.module}-${entry.key}`,
								onSelect: () => {
									onOpenChange(false);
									router.visit(entry.href);
								},
								children: [/* @__PURE__ */ jsx(MenuIcon, { name: group.entries.length === 1 ? group.key : entry.icon }), entry.label]
							}, `${entry.module}.${entry.key}`))
						}, group.key);
					})]
				})]
			})]
		})
	});
}
export { AdminSearchDialog };
