import { t as Note } from "./Note-BdXDWF9_.js";
import { router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { jsx } from "react/jsx-runtime";
// resources/js/lib/fresh-refusal.ts
/** The mark for this render: taken now on the render it opens, kept while it stays open, dropped as it closes. */
function openedAtNow(previous, open, visits) {
	if (!open) return null;
	return previous ?? visits;
}
/** The refusal to say: the page's, once a visit has finished since the opening; none while it is closed. */
function freshRefusal(openedAt, visits, message) {
	return openedAt !== null && visits > openedAt && message !== void 0 && message !== "" ? message : void 0;
}
// resources/js/components/FormError.tsx
/**
* The page's refusal, but only one that arrived while this dialog was open.
*
* `errors.form` belongs to the page and stays until the next visit; opening a dialog is not a visit.
* Read plainly, a refusal of Block showed inside the Close Account dialog opened afterwards, before
* anything had been sent from it (the review of the Geist move, 2026-10-02). Counting the visits
* that finish after the dialog opened tells this dialog's own answer from an older one.
*
* The moment of opening is marked on the render it opens (lib/fresh-refusal.ts), by adjusting state
* while rendering, so React draws again before anything shows: marked in an effect, it came a render
* late, and a dialog opened again said the older refusal for that one render.
*/
function useFreshRefusal(open) {
	const { errors } = usePage().props;
	const [visits, setVisits] = useState(0);
	const [openedAt, setOpenedAt] = useState(null);
	useEffect(() => router.on("finish", () => setVisits((count) => count + 1)), []);
	const mark = openedAtNow(openedAt, open, visits);
	if (mark !== openedAt) setOpenedAt(mark);
	return freshRefusal(mark, visits, errors.form);
}
/** The refusal of what a dialog itself sent, said inside it (it stays open on a refusal). */
function DialogError({ open }) {
	const message = useFreshRefusal(open);
	return message === void 0 ? null : /* @__PURE__ */ jsx(Note, {
		variant: "error",
		alert: true,
		"data-test": "form-error",
		children: message
	});
}
function FormError() {
	const { errors } = usePage().props;
	const message = errors.form;
	if (message === void 0 || message === "") return null;
	return /* @__PURE__ */ jsx(Note, {
		variant: "error",
		alert: true,
		"data-test": "form-error",
		children: message
	});
}
export { FormError as n, useFreshRefusal as r, DialogError as t };
