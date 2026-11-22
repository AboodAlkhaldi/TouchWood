import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { jsx } from "react/jsx-runtime";
import { Label } from "radix-ui";
// resources/js/components/ui/label.tsx
function Label$1({ className, ...props }) {
	return /* @__PURE__ */ jsx(Label.Root, {
		"data-slot": "label",
		className: cn("flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50", className),
		...props
	});
}
export { Label$1 as t };
