import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { CircleIcon } from "lucide-react";
import { jsx } from "react/jsx-runtime";
import { RadioGroup } from "radix-ui";
// resources/js/components/ui/radio-group.tsx
function RadioGroup$1({ className, ...props }) {
	return /* @__PURE__ */ jsx(RadioGroup.Root, {
		"data-slot": "radio-group",
		className: cn("grid gap-3", className),
		...props
	});
}
function RadioGroupItem({ className, ...props }) {
	return /* @__PURE__ */ jsx(RadioGroup.Item, {
		"data-slot": "radio-group-item",
		className: cn("aspect-square size-4 shrink-0 rounded-full border border-input text-primary shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:ring-destructive/40", className),
		...props,
		children: /* @__PURE__ */ jsx(RadioGroup.Indicator, {
			"data-slot": "radio-group-indicator",
			className: "relative flex items-center justify-center",
			children: /* @__PURE__ */ jsx(CircleIcon, { className: "absolute top-1/2 start-1/2 size-2 -translate-x-1/2 rtl:translate-x-1/2 -translate-y-1/2 fill-primary" })
		})
	});
}
export { RadioGroupItem as n, RadioGroup$1 as t };
