import { i as FieldError, o as FieldLabel, r as FieldDescription, t as Field } from "./field-Ce02Vx8L.js";
import { t as Input } from "./input-DlWSmFH_.js";
import { t as NativeSelect } from "./native-select-C9cFEAFg.js";
import { t as Textarea } from "./textarea-CmN2NAWT.js";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/Fields.tsx
function describedBy(id, helper, error, also) {
	const ids = [
		also ?? null,
		helper === void 0 || helper === null ? null : `${id}-helper`,
		error ? `${id}-error` : null
	].filter(Boolean);
	return ids.length === 0 ? void 0 : ids.join(" ");
}
/** Under a field: its helper, what is wrong, and - for a checked field - the line a screen reader hears. */
function Messages({ id, helper, error, check }) {
	const typed = check?.typed === true;
	return /* @__PURE__ */ jsxs(Fragment, { children: [
		helper === void 0 || helper === null ? null : /* @__PURE__ */ jsx(FieldDescription, {
			id: `${id}-helper`,
			children: helper
		}),
		error ? /* @__PURE__ */ jsx(FieldError, {
			id: `${id}-error`,
			role: typed ? void 0 : "alert",
			children: error
		}) : null,
		check === void 0 ? null : /* @__PURE__ */ jsx("span", {
			className: "sr-only",
			"aria-live": "polite",
			children: typed ? error : ""
		})
	] });
}
/**
* What a control does with its field's check: says it was typed in, or left. The browser's own
* checks stand aside for it - "required", and an email box's shape, which the browser would
* otherwise say in a bubble of its own language when Enter sends the form - the email keyboard kept.
*/
function checked(check, control) {
	if (check === void 0) return {};
	return {
		...control.type === "email" ? {
			type: "text",
			inputMode: "email"
		} : {},
		required: void 0,
		"aria-required": check.required || control.required ? true : void 0,
		onBlur: (event) => {
			control.onBlur?.(event);
			check.onLeave();
		}
	};
}
function TextField({ id, label, helper, error, className, inputClassName, alsoDescribedBy, check, ...input }) {
	const shown = check === void 0 ? error : check.message;
	return /* @__PURE__ */ jsxs(Field, {
		className,
		children: [
			/* @__PURE__ */ jsx(FieldLabel, {
				htmlFor: id,
				children: label
			}),
			/* @__PURE__ */ jsx(Input, {
				...input,
				...checked(check, input),
				onChange: (event) => {
					input.onChange?.(event);
					check?.onType();
				},
				id,
				className: inputClassName,
				"aria-invalid": shown ? true : void 0,
				"aria-describedby": describedBy(id, helper, shown, alsoDescribedBy)
			}),
			/* @__PURE__ */ jsx(Messages, {
				id,
				helper,
				error: shown,
				check
			})
		]
	});
}
function TextareaField({ id, label, helper, error, className, alsoDescribedBy, check, ...input }) {
	const shown = check === void 0 ? error : check.message;
	return /* @__PURE__ */ jsxs(Field, {
		className,
		children: [
			/* @__PURE__ */ jsx(FieldLabel, {
				htmlFor: id,
				children: label
			}),
			/* @__PURE__ */ jsx(Textarea, {
				...input,
				...checked(check, input),
				onChange: (event) => {
					input.onChange?.(event);
					check?.onType();
				},
				id,
				"aria-invalid": shown ? true : void 0,
				"aria-describedby": describedBy(id, helper, shown, alsoDescribedBy)
			}),
			/* @__PURE__ */ jsx(Messages, {
				id,
				helper,
				error: shown,
				check
			})
		]
	});
}
function SelectField({ id, label, helper, error, className, alsoDescribedBy, check, children, ...select }) {
	const shown = check === void 0 ? error : check.message;
	return /* @__PURE__ */ jsxs(Field, {
		className,
		children: [
			/* @__PURE__ */ jsx(FieldLabel, {
				htmlFor: id,
				children: label
			}),
			/* @__PURE__ */ jsx(NativeSelect, {
				...select,
				...checked(check, select),
				onChange: (event) => {
					select.onChange?.(event);
					check?.onType();
				},
				id,
				"aria-invalid": shown ? true : void 0,
				"aria-describedby": describedBy(id, helper, shown, alsoDescribedBy),
				children
			}),
			/* @__PURE__ */ jsx(Messages, {
				id,
				helper,
				error: shown,
				check
			})
		]
	});
}
export { checked as a, TextareaField as i, SelectField as n, describedBy as o, TextField as r, Messages as t };
