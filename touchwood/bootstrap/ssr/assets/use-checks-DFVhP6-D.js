import { n as useTranslator } from "./t-Da8CTZFi.js";
import { n as intlLocale, r as toLatinDigits } from "./digits-D5IxcRsf.js";
import { usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
// resources/js/lib/checks.ts
/** A number as a person types it: a minus, digits, one point and more digits. */
var NUMBER = /^-?\d+(\.\d+)?$/;
var DIGITS = /^\d+$/;
var LETTERS = /^[A-Za-z]+$/;
var EMAIL = /^[^\s@]+@[^\s@]+$/;
var PHONE = /^\+[1-9]\d{6,14}$/;
/** A phone number cleaned as Access's PhoneNumber cleans it, before its shape is read. */
function phoneOf(text) {
	const digits = toLatinDigits(text).replace(/[\s\-.()]/g, "");
	return digits.startsWith("00") ? `+${digits.slice(2)}` : digits;
}
/**
* What is wrong with a value, or null when the box would send it. An empty box is wrong only when
* it is required: nothing else is said about a value that is not there.
*/
function problemOf(value, rules) {
	const text = rules.keepSpaces === true ? value : value.trim();
	if (text === "") return rules.required === true ? {
		rule: "required",
		values: {}
	} : null;
	if (rules.number !== void 0) return numberProblem(text, rules.number);
	if (rules.digits === true && !DIGITS.test(text)) return {
		rule: "digits",
		values: {}
	};
	if (rules.letters === true && !LETTERS.test(text)) return {
		rule: "letters",
		values: {}
	};
	const length = lengthProblem(text, rules.length, rules.digits === true);
	if (length !== null) return length;
	if (rules.email === true && !EMAIL.test(text)) return {
		rule: "email",
		values: {}
	};
	if (rules.phone === true && !PHONE.test(phoneOf(text))) return {
		rule: "phone",
		values: {}
	};
	if (rules.format !== void 0 && !rules.format.pattern.test(text)) return {
		rule: "format",
		values: {},
		key: rules.format.key
	};
	const own = rules.own?.(text) ?? null;
	return own === null ? null : {
		rule: "format",
		values: {},
		key: own
	};
}
function numberProblem(text, rule) {
	if (!NUMBER.test(text)) return {
		rule: "number",
		values: {}
	};
	const decimals = rule.decimals ?? 0;
	if ((text.includes(".") ? text.length - text.indexOf(".") - 1 : 0) > decimals) return decimals === 0 ? {
		rule: "whole",
		values: {}
	} : {
		rule: decimals === 1 ? "decimals_one" : "decimals",
		values: { count: decimals }
	};
	const number = Number(text);
	const { min, max } = rule;
	if (min !== void 0 && number < min || max !== void 0 && number > max) {
		if (min !== void 0 && max !== void 0) return {
			rule: "range",
			values: {
				min,
				max
			}
		};
		return min !== void 0 ? {
			rule: "at_least",
			values: { min }
		} : {
			rule: "at_most",
			values: { max: max ?? 0 }
		};
	}
	return null;
}
function lengthProblem(text, rule, digits) {
	if (rule === void 0) return null;
	const length = rule.of === void 0 ? [...text].length : rule.of(text);
	if (rule.min !== void 0 && length < rule.min) return {
		rule: digits ? "min_digits" : "min_length",
		values: { min: rule.min }
	};
	if (rule.max !== void 0 && length > rule.max) {
		if (rule.max === 1) return {
			rule: "max_length_one",
			values: {}
		};
		return {
			rule: digits ? "max_digits" : "max_length",
			values: { max: rule.max }
		};
	}
	return null;
}
/**
* The problem as a sentence naming the box (Geist: "Email address is required."), its figures in
* Latin digits grouped as the page's language groups them (frontend.md §1.8).
*/
function sentenceOf(problem, subject, t, locale) {
	const format = new Intl.NumberFormat(intlLocale(locale));
	const values = { field: subject };
	for (const [name, figure] of Object.entries(problem.values)) values[name] = format.format(figure);
	return t(problem.key ?? `ui.check.${problem.rule}`, values);
}
/**
* A box's label as the subject of a sentence: in English, sentence case - every later word that is
* only capitalised is lowered, an all-capital one (SMS, VAT) and a language's name kept; Arabic has
* no case and is kept as it is.
*/
function subjectOf(label, locale) {
	if (locale === "ar") return label;
	return label.replace(/(?<=\S\s+\(?)\p{Lu}\p{Ll}+/gu, (word) => KEPT.has(word) ? word : word.toLowerCase());
}
var KEPT = /* @__PURE__ */ new Set(["Arabic", "English"]);
// resources/js/lib/use-checks.ts
/**
* @param fresh  Anything that changes when the form starts again - a dialog that stays on the page
*   opening or closing (`open`): the boxes it said were wrong before are forgotten, as on a new form.
*/
function useChecks(boxes, fresh) {
	const t = useTranslator();
	const { locale } = usePage().props;
	const [shown, setShown] = useState(() => /* @__PURE__ */ new Set());
	const sent = useRef(/* @__PURE__ */ new Map());
	const pressing = useRef(false);
	const left = useRef([]);
	const [was, setWas] = useState(() => fresh);
	if (was !== fresh) {
		setWas(() => fresh);
		setShown(/* @__PURE__ */ new Set());
	}
	useEffect(() => {
		let late;
		const said = () => {
			const ids = left.current;
			left.current = [];
			setShown((was) => ids.every((id) => was.has(id)) ? was : /* @__PURE__ */ new Set([...was, ...ids]));
		};
		const down = () => {
			clearTimeout(late);
			pressing.current = true;
		};
		const click = () => {
			clearTimeout(late);
			pressing.current = false;
			setTimeout(said, 0);
		};
		const up = () => {
			clearTimeout(late);
			late = setTimeout(() => {
				pressing.current = false;
				said();
			}, 500);
		};
		document.addEventListener("pointerdown", down, true);
		document.addEventListener("click", click, true);
		document.addEventListener("pointerup", up, true);
		document.addEventListener("pointercancel", up, true);
		return () => {
			clearTimeout(late);
			document.removeEventListener("pointerdown", down, true);
			document.removeEventListener("click", click, true);
			document.removeEventListener("pointerup", up, true);
			document.removeEventListener("pointercancel", up, true);
		};
	}, []);
	const problems = /* @__PURE__ */ new Map();
	for (const box of boxes) {
		const problem = box.off === true ? null : problemOf(box.value, box.rules);
		if (problem !== null) problems.set(box.id, sentenceOf(problem, box.subject ?? subjectOf(box.label, locale), t, locale));
	}
	const show = (id) => setShown((was) => was.has(id) ? was : new Set(was).add(id));
	const first = boxes.find((box) => shown.has(box.id) && problems.has(box.id));
	return {
		box: (id, refusal) => {
			const box = boxes.find((each) => each.id === id);
			const own = shown.has(id) ? problems.get(id) : void 0;
			const held = sent.current.get(id);
			const server = refusal !== void 0 && refusal !== "" && (held === void 0 || held === box?.value) ? refusal : void 0;
			return {
				message: own ?? server,
				typed: own !== void 0,
				required: box?.rules.required === true,
				onType: () => show(id),
				onLeave: () => {
					if (pressing.current) left.current.push(id);
					else show(id);
				}
			};
		},
		reason: first === void 0 ? void 0 : problems.get(first.id),
		submit: (send) => {
			const wrong = boxes.filter((box) => problems.has(box.id));
			left.current = [];
			if (wrong.length === 0) {
				sent.current = new Map(boxes.map((box) => [box.id, box.value]));
				setShown(/* @__PURE__ */ new Set());
				send();
				return;
			}
			setShown(new Set(boxes.map((box) => box.id)));
			document.getElementById(wrong[0]?.focus ?? wrong[0]?.id ?? "")?.focus();
		}
	};
}
export { subjectOf as i, problemOf as n, sentenceOf as r, useChecks as t };
