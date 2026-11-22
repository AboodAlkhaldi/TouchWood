import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { jsx } from "react/jsx-runtime";
// resources/js/components/StoreOffBadge.tsx
/**
* The Off mark beside a store that is switched off (platform.md §1.6; access.md amendment 58): its
* staff, customers and addresses are kept and shown, marked, so it can reopen as it was.
*/
function StoreOffBadge({ className }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsx(Badge, {
		variant: "outline",
		className,
		"data-test": "store-off",
		children: t("admin.store.off")
	});
}
export { StoreOffBadge as t };
