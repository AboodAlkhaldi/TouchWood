import { t as cn } from "./cn-C2NdifnB.js";
import { Loader2Icon } from "lucide-react";
import { jsx } from "react/jsx-runtime";
// resources/js/components/ui/spinner.tsx
function Spinner({ className, ...props }) {
	return /* @__PURE__ */ jsx(Loader2Icon, {
		role: "status",
		"aria-label": "Loading",
		className: cn("size-4 animate-spin", className),
		...props
	});
}
export { Spinner as t };
