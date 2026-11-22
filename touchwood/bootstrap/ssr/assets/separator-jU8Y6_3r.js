import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { jsx } from "react/jsx-runtime";
import { Separator } from "radix-ui";
// resources/js/components/ui/separator.tsx
function Separator$1({ className, orientation = "horizontal", decorative = true, ...props }) {
	return /* @__PURE__ */ jsx(Separator.Root, {
		"data-slot": "separator",
		decorative,
		orientation,
		className: cn("shrink-0 bg-border data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px", className),
		...props
	});
}
export { Separator$1 as t };
