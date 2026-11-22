import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { o as FieldLabel, t as Field } from "./field-Ce02Vx8L.js";
import { t as Input } from "./input-DlWSmFH_.js";
import { a as checked, o as describedBy, t as Messages } from "./Fields-BdHuDKNr.js";
import { n as intlLocale, t as figure } from "./digits-D5IxcRsf.js";
import { i as subjectOf, r as sentenceOf, t as useChecks } from "./use-checks-DFVhP6-D.js";
import { H as useLink } from "../ssr.js";
import { Link, router, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/ops/NewsletterBox.tsx
function NewsletterBox({ words }) {
	const t = useTranslator();
	const link = useLink();
	const { shop, locale, csrfToken } = usePage().props;
	const [email, setEmail] = useState("");
	const [sending, setSending] = useState(false);
	const [sent, setSent] = useState(false);
	const [refusal, setRefusal] = useState(null);
	const [boxRefusal, setBoxRefusal] = useState(void 0);
	const sentLine = useRef(null);
	const form = useRef(null);
	const checks = useChecks([{
		id: "newsletter-email",
		label: words.email,
		value: email,
		rules: {
			required: true,
			email: true,
			length: {
				min: 3,
				max: 254
			}
		}
	}]);
	const check = checks.box("newsletter-email", boxRefusal);
	useEffect(() => {
		if (sent) sentLine.current?.focus();
	}, [sent]);
	if (shop === null || shop === void 0) return null;
	const address = `/${shop.code}/${locale}/newsletter`;
	function fail() {
		setRefusal({
			text: words.failed,
			signIn: false
		});
		setSending(false);
	}
	/**
	* One send. A 419 means the session behind the page's token is gone (it expired while the page stood open): the
	* page is read again for a fresh token, keeping what was typed, and the send goes once more with it.
	*/
	async function send(token, again) {
		setSending(true);
		setRefusal(null);
		setBoxRefusal(void 0);
		let status;
		let answer;
		try {
			const response = await fetch(address, {
				method: "POST",
				credentials: "same-origin",
				headers: {
					Accept: "application/json",
					"Content-Type": "application/json",
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": token
				},
				body: JSON.stringify({ email })
			});
			status = response.status;
			answer = await response.json().catch(() => ({}));
		} catch {
			fail();
			return;
		}
		if (status === 419 && !again) {
			router.reload({
				onSuccess: (page) => void send(page.props.csrfToken, true),
				onNetworkError: () => {
					fail();
					return false;
				}
			});
			return;
		}
		if (status === 202) setSent(true);
		else if (status === 409 && answer.type === "ops.subscribe_in_your_account" && typeof answer.detail === "string") setRefusal({
			text: answer.detail,
			signIn: true
		});
		else if (status === 422) setBoxRefusal(sentenceOf({
			rule: "email",
			values: {}
		}, subjectOf(words.email, locale), t, locale));
		else if (status === 429 && typeof answer.retryAfter === "number") {
			const minutes = Math.max(1, Math.ceil(answer.retryAfter / 60));
			const sentence = words.too_many[new Intl.PluralRules(intlLocale(locale)).select(minutes)] ?? words.too_many.other ?? "";
			setRefusal({
				text: sentence.replace(":count", figure(locale, minutes)),
				signIn: false
			});
		} else if (status === 503 && answer.type === "ops.newsletter_unavailable" && typeof answer.detail === "string") setRefusal({
			text: answer.detail,
			signIn: false
		});
		else {
			fail();
			return;
		}
		setSending(false);
	}
	return /* @__PURE__ */ jsxs("section", {
		"aria-labelledby": "newsletter-title",
		className: "grid gap-3",
		"data-test": "newsletter-box",
		children: [/* @__PURE__ */ jsxs("div", {
			className: "grid gap-1",
			children: [/* @__PURE__ */ jsx("h2", {
				id: "newsletter-title",
				className: "text-heading-16 text-white",
				children: words.title
			}), /* @__PURE__ */ jsx("p", {
				className: "text-copy-14 text-white/80",
				children: words.text
			})]
		}), sent ? /* @__PURE__ */ jsx("p", {
			ref: sentLine,
			tabIndex: -1,
			role: "status",
			className: "text-copy-14 text-white outline-none",
			"data-test": "newsletter-sent",
			children: words.sent
		}) : /* @__PURE__ */ jsxs("form", {
			ref: form,
			noValidate: true,
			"data-mode": "light",
			className: "grid gap-2 rounded-lg bg-white p-3 text-ink [color-scheme:light]",
			onSubmit: (event) => {
				event.preventDefault();
				if (!sending) checks.submit(() => void send(csrfToken, false));
			},
			children: [/* @__PURE__ */ jsxs("div", {
				className: "flex flex-col gap-2 sm:flex-row sm:items-start",
				children: [/* @__PURE__ */ jsxs(Field, {
					className: "sm:max-w-sm sm:flex-1",
					children: [
						/* @__PURE__ */ jsx(FieldLabel, {
							htmlFor: "newsletter-email",
							className: "sr-only",
							children: words.email
						}),
						/* @__PURE__ */ jsx(Input, {
							...checked(check, {
								type: "email",
								required: true
							}),
							id: "newsletter-email",
							name: "email",
							autoComplete: "email",
							dir: "ltr",
							value: email,
							onChange: (event) => {
								setEmail(event.target.value);
								setRefusal(null);
								check.onType();
							},
							"aria-invalid": check.message ? true : void 0,
							"aria-describedby": describedBy("newsletter-email", void 0, check.message, refusal ? "newsletter-refusal" : void 0),
							"data-test": "newsletter-email"
						}),
						/* @__PURE__ */ jsx(Messages, {
							id: "newsletter-email",
							error: check.message,
							check
						})
					]
				}), /* @__PURE__ */ jsx(ActionButton, {
					type: "button",
					loading: sending,
					onClick: () => form.current?.requestSubmit(),
					className: "bg-logo-copper-light text-logo-navy hover:bg-logo-copper-light/90",
					"data-test": "newsletter-subscribe",
					children: words.action
				})]
			}), refusal ? /* @__PURE__ */ jsxs("p", {
				id: "newsletter-refusal",
				role: "alert",
				className: "text-copy-14 text-bad",
				"data-test": "newsletter-refusal",
				children: [
					refusal.text,
					" ",
					refusal.signIn ? /* @__PURE__ */ jsx(Link, {
						href: link("storefront.sign-in"),
						className: "font-medium underline underline-offset-4",
						"data-test": "newsletter-sign-in",
						children: words.sign_in
					}) : null
				]
			}) : null]
		})]
	});
}
export { NewsletterBox as default };
