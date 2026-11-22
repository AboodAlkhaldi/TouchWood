import { t as cn } from "./cn-C2NdifnB.js";
import { jsx } from "react/jsx-runtime";
// resources/js/components/Money.tsx
function Money({ value, className }) {
	return /* @__PURE__ */ jsx("bdi", {
		dir: "ltr",
		className: cn("tw-figure", className),
		"data-minor": value.minor,
		children: value.text
	});
}
export { Money as t };
