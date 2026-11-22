import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { ChevronDownIcon } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/ui/native-select.tsx
function NativeSelect({ className, size = "default", ...props }) {
	return /* @__PURE__ */ jsxs("div", {
		className: "group/native-select relative w-fit has-[select:disabled]:opacity-50",
		"data-slot": "native-select-wrapper",
		children: [/* @__PURE__ */ jsx("select", {
			"data-slot": "native-select",
			"data-size": size,
			className: cn("h-9 w-full min-w-0 appearance-none rounded-md border border-input bg-transparent px-3 py-2 pe-9 text-sm shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground disabled:pointer-events-none disabled:cursor-not-allowed data-[size=sm]:h-8 data-[size=sm]:py-1 dark:bg-input/30 dark:hover:bg-input/50", "focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50", "aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40", className),
			...props
		}), /* @__PURE__ */ jsx(ChevronDownIcon, {
			className: "pointer-events-none absolute top-1/2 end-3.5 size-4 -translate-y-1/2 text-muted-foreground opacity-50 select-none",
			"aria-hidden": "true",
			"data-slot": "native-select-icon"
		})]
	});
}
function NativeSelectOption({ className, ...props }) {
	return /* @__PURE__ */ jsx("option", {
		"data-slot": "native-select-option",
		className: cn("bg-[Canvas] text-[CanvasText]", className),
		...props
	});
}
export { NativeSelectOption as n, NativeSelect as t };
