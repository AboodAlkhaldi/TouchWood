import { t as Calendar } from "./calendar-B0sUpM0L.js";
import { jsx } from "react/jsx-runtime";
import { ar } from "react-day-picker/locale/ar";
// resources/js/components/DateCalendar.tsx
function DateCalendar({ selected, earliest, latest, today, onSelect, arabic }) {
	return /* @__PURE__ */ jsx(Calendar, {
		mode: "single",
		selected,
		defaultMonth: selected ?? earliest ?? latest,
		startMonth: earliest,
		endMonth: latest,
		onSelect,
		disabled: [...earliest === void 0 ? [] : [{ before: earliest }], ...latest === void 0 ? [] : [{ after: latest }]],
		today,
		locale: arabic ? ar : void 0,
		dir: arabic ? "rtl" : "ltr",
		numerals: "latn"
	});
}
export { DateCalendar as default };
