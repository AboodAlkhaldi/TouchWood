import { t as Calendar } from "./calendar-B0sUpM0L.js";
import { jsx } from "react/jsx-runtime";
import { ar } from "react-day-picker/locale/ar";
// resources/js/components/DateRangeCalendar.tsx
function DateRangeCalendar({ selected, defaultMonth, today, onSelect, arabic }) {
	return /* @__PURE__ */ jsx(Calendar, {
		mode: "range",
		selected,
		defaultMonth,
		endMonth: today,
		onSelect,
		disabled: { after: today },
		today,
		numberOfMonths: 2,
		locale: arabic ? ar : void 0,
		dir: arabic ? "rtl" : "ltr",
		numerals: "latn"
	});
}
export { DateRangeCalendar as default };
