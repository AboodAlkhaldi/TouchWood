import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { t as Note } from "./Note-BdXDWF9_.js";
import { i as DialogDescription, o as DialogHeader, r as DialogContent, s as DialogTitle, t as Dialog } from "./dialog-C7NRbZ1B.js";
import { H as useLink, a as payAddresses, c as useClock, i as minutesAndSeconds, l as useCsrfToken, n as P, o as postJson, r as endingOf, s as untilOf, t as CHANNEL, u as useServerNow } from "../ssr.js";
import { router } from "@inertiajs/react";
import { useCallback, useEffect, useRef, useState } from "react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/sales/payment/PaymentBlock.tsx
var FORM_BOX = "payment-form";
/** A press the form never answers gives the page back rather than leave it locked. */
var PRESS_LIMIT = 6e4;
var ASK_WHILE_ON_BANK_PAGE = 3e4;
var ASK_WHILE_CONFIRMING = 1e4;
/**
* A request's refusals that a fresh page answers: the link ended, a payment already open, the amount moved. Any other
* (the gateway off or its key refused, the request no longer open in this mode) is said where the form would be: the
* request's page doesn't change with the gateway, so reloading it would only ask again, for ever.
*/
var REQUEST_RELOADS = [
	"payment_link_ended",
	"payment_in_progress",
	"amount_changed"
];
/** From Pay until the answer: nothing on the page may change (Q3). */
var LOCKED = [
	"pressed",
	"sending",
	"live"
];
var scripts = /* @__PURE__ */ new Map();
/** The form's script, from the address our server named for the mode (rule 10), loaded once and only on a page that pays. */
function loadScript(src) {
	let loading = scripts.get(src);
	if (loading === void 0) {
		loading = new Promise((resolve, reject) => {
			const script = document.createElement("script");
			script.src = src;
			script.async = true;
			script.onload = () => resolve();
			script.onerror = () => {
				scripts.delete(src);
				script.remove();
				reject(/* @__PURE__ */ new Error("The form did not load."));
			};
			document.head.appendChild(script);
		});
		scripts.set(src, loading);
	}
	return loading;
}
/** At checkout, for its quote; on a request's page, for the order with this number (§4.2). */
function PaymentBlock({ quoteId = "", number, press, agreed, shown, onState, onClosed }) {
	const t = useTranslator();
	const link = useLink();
	const addresses = payAddresses(link, number);
	const formFailed = number === void 0 ? `${P}.form_failed` : `${P}.request.form_failed`;
	const token = useCsrfToken();
	const [phase, setPhase] = useState({ kind: "opening" });
	const [notice, setNotice] = useState(null);
	const session = useRef(null);
	const quote = useRef(quoteId);
	quote.current = quoteId;
	const terms = useRef(shown);
	terms.current = shown;
	const again = useCallback((words) => {
		setNotice(words);
		setPhase({ kind: "reloading" });
		router.reload({ onFinish: () => setPhase({ kind: "opening" }) });
	}, []);
	/** The details are ready: our server starts the try, with the quote and the session alone. */
	const send = useCallback(async () => {
		if (number === void 0 && agreed?.current !== true) {
			setNotice(t("sales::shop_checkout.terms.needed"));
			setPhase({ kind: "form" });
			return;
		}
		setNotice(null);
		setPhase({ kind: "sending" });
		const answer = await postJson(addresses.pay, token, {
			...number === void 0 ? {
				quote: quote.current,
				agreed: true,
				...terms.current
			} : {},
			session: session.current ?? ""
		});
		if (answer.ok) {
			const { tryId, frameUrl, windowEndsAt, holdEndsAt, serverNow } = answer.data;
			setPhase({
				kind: "live",
				live: {
					tryId,
					frameUrl,
					windowEndsAt,
					holdEndsAt,
					serverNow,
					frameOpen: frameUrl !== null,
					finished: frameUrl === null,
					view: null
				}
			});
		} else if (answer.status === 429) {
			setNotice(t(`${P}.too_many`));
			setPhase({ kind: "form" });
		} else if (answer.status === 0 || answer.status >= 500) again(null);
		else if (number !== void 0 && !REQUEST_RELOADS.some((type) => answer.type.includes(type))) setPhase({
			kind: "failed",
			words: answer.detail === "" ? t(formFailed) : answer.detail
		});
		else again(answer.type.includes("quote_changed") || answer.type.includes("quote_expired") ? t(`${P}.changed`) : answer.detail);
	}, [again, token]);
	const quoteOpened = useRef(quoteId);
	useEffect(() => {
		if (quoteOpened.current !== quoteId && (phase.kind === "form" || phase.kind === "failed")) {
			quoteOpened.current = quoteId;
			setPhase({ kind: "opening" });
		}
	}, [quoteId, phase.kind]);
	useEffect(() => {
		if (phase.kind !== "opening") return;
		let gone = false;
		(async () => {
			quoteOpened.current = quoteId;
			const answer = await postJson(addresses.session, token, number === void 0 ? { quote: quoteId } : {});
			if (gone) return;
			if (!answer.ok) {
				if (answer.status === 409 && (number === void 0 || REQUEST_RELOADS.some((type) => answer.type.includes(type)))) again(null);
				else if (answer.status === 409) setPhase({
					kind: "failed",
					words: answer.detail === "" ? t(formFailed) : answer.detail
				});
				else setPhase({
					kind: "failed",
					words: answer.status === 429 ? t(`${P}.too_many`) : t(formFailed)
				});
				return;
			}
			try {
				await loadScript(answer.data.scriptUrl);
			} catch {
				if (!gone) setPhase({
					kind: "failed",
					words: t(formFailed)
				});
				return;
			}
			if (gone) return;
			session.current = answer.data.sessionId;
			document.getElementById(FORM_BOX)?.replaceChildren();
			try {
				if (window.myfatoorah === void 0) throw new Error("The form did not load.");
				window.myfatoorah.init({
					sessionId: answer.data.sessionId,
					containerId: FORM_BOX,
					callback: (response) => {
						if (response.isSuccess === true) send();
						else {
							setNotice(t(`${P}.form_refused`));
							setPhase({ kind: "form" });
						}
					},
					settings: {
						loader: { display: "none" },
						card: { style: { button: { useCustomButton: true } } }
					}
				});
				setPhase({ kind: "form" });
			} catch {
				setPhase({
					kind: "failed",
					words: t(formFailed)
				});
			}
		})();
		return () => {
			gone = true;
		};
	}, [phase.kind, quoteId]);
	press.current = () => {
		if (phase.kind !== "form" || window.myfatoorah === void 0) return;
		setNotice(null);
		setPhase({ kind: "pressed" });
		window.myfatoorah.validateCardInputs().then(() => window.myfatoorah?.submitCardPayment()).catch(() => setPhase({ kind: "form" }));
	};
	useEffect(() => {
		if (phase.kind !== "pressed") return;
		const timer = setTimeout(() => setPhase((now) => now.kind === "pressed" ? { kind: "form" } : now), PRESS_LIMIT);
		return () => clearTimeout(timer);
	}, [phase.kind]);
	useEffect(() => {
		onState({
			ready: phase.kind === "form",
			locked: LOCKED.includes(phase.kind),
			failed: phase.kind === "failed"
		});
	}, [phase.kind]);
	return /* @__PURE__ */ jsxs("div", {
		className: "grid gap-3",
		"data-test": "payment-block",
		children: [
			notice === null ? null : /* @__PURE__ */ jsx("p", {
				role: "status",
				className: "text-copy-14 text-ink",
				"data-test": "payment-notice",
				children: notice
			}),
			phase.kind === "failed" ? /* @__PURE__ */ jsxs("div", {
				className: "grid justify-items-start gap-2",
				"data-test": "payment-failed",
				children: [/* @__PURE__ */ jsx("p", {
					className: "text-copy-14 text-ink",
					children: phase.words
				}), /* @__PURE__ */ jsx(Button, {
					variant: "outline",
					onClick: () => setPhase({ kind: "opening" }),
					"data-test": "payment-try-again",
					children: t(`${P}.try_again`)
				})]
			}) : null,
			phase.kind === "opening" || phase.kind === "reloading" ? /* @__PURE__ */ jsx("p", {
				className: "text-copy-14 text-ink-muted",
				children: t(`${P}.loading`)
			}) : null,
			phase.kind === "live" ? /* @__PURE__ */ jsx(Paying, {
				live: phase.live,
				number,
				change: (next) => setPhase((now) => now.kind === "live" ? {
					kind: "live",
					live: {
						...now.live,
						...next
					}
				} : now),
				onOver: (view) => {
					if (view.mainStoreUrl !== null) onClosed(view);
					else if (view.orderNumber !== null) router.visit(addresses.done(view.orderNumber));
					else again(endingOf(view, t));
				},
				onLost: () => again(null)
			}) : null,
			/* @__PURE__ */ jsx("div", {
				id: FORM_BOX,
				inert: phase.kind !== "form",
				hidden: phase.kind === "failed",
				className: "min-w-0",
				"data-test": "payment-form"
			})
		]
	});
}
/** From Pay to the answer: the time left, the bank's page, and asking our server. */
function Paying({ live, number, change, onOver, onLost }) {
	const t = useTranslator();
	const link = useLink();
	const token = useCsrfToken();
	const clock = useClock();
	const now = useServerNow(live.serverNow);
	const frame = useRef(null);
	const [leaving, setLeaving] = useState(false);
	const left = new Date(live.windowEndsAt).getTime() - now;
	const windowOver = left <= 0;
	const latest = useRef(live);
	latest.current = live;
	const asking = useRef(false);
	const timer = useRef(void 0);
	const gone = useRef(false);
	const ask = useCallback(async () => {
		if (asking.current || gone.current) return;
		asking.current = true;
		clearTimeout(timer.current);
		const answer = await postJson(payAddresses(link, number).follow(latest.current.tryId), token);
		asking.current = false;
		if (gone.current) return;
		if (answer.ok && answer.data.state !== "LIVE") {
			onOver(answer.data);
			return;
		}
		if (!answer.ok && (answer.status === 404 || answer.status === 401 || answer.status === 419)) {
			onLost();
			return;
		}
		if (answer.ok) change({ view: answer.data });
		const untilWindow = new Date(latest.current.windowEndsAt).getTime() - Date.now();
		const every = latest.current.finished || untilWindow <= 0 ? ASK_WHILE_CONFIRMING : ASK_WHILE_ON_BANK_PAGE;
		timer.current = setTimeout(() => void ask(), every);
	}, [token]);
	useEffect(() => {
		gone.current = false;
		if (latest.current.frameUrl === null) ask();
		else timer.current = setTimeout(() => void ask(), ASK_WHILE_ON_BANK_PAGE);
		return () => {
			gone.current = true;
			clearTimeout(timer.current);
		};
	}, [ask]);
	useEffect(() => {
		if (windowOver) {
			change({ frameOpen: false });
			setLeaving(false);
			ask();
		}
	}, [windowOver]);
	useEffect(() => {
		if (live.frameUrl === null) return;
		const bank = new URL(live.frameUrl).origin;
		const heard = (event) => {
			if (frame.current === null || event.source !== frame.current.contentWindow) return;
			const ours = event.origin === window.location.origin && event.data?.sender === "TW-payment-return";
			let theirs = false;
			if (event.origin === bank) try {
				theirs = JSON.parse(String(event.data)).sender === "MF-3DSecure";
			} catch {
				theirs = false;
			}
			if (ours || theirs) {
				change({
					frameOpen: false,
					finished: true
				});
				setLeaving(false);
				ask();
			}
		};
		window.addEventListener("message", heard);
		return () => window.removeEventListener("message", heard);
	}, [live.frameUrl, ask]);
	useEffect(() => {
		if (typeof BroadcastChannel === "undefined") return;
		const channel = new BroadcastChannel(CHANNEL);
		channel.onmessage = (event) => {
			if (event.data === "ping") channel.postMessage("here");
		};
		return () => channel.close();
	}, []);
	const view = live.view;
	const until = (view === null ? null : untilOf(view)) ?? live.holdEndsAt;
	const waitOver = view?.waitEndsAt !== null && view?.waitEndsAt !== void 0 && new Date(view.waitEndsAt).getTime() <= now;
	return /* @__PURE__ */ jsxs(Fragment$1, { children: [view?.payment === "UNKNOWN" && waitOver ? /* @__PURE__ */ jsx(Note, {
		"data-test": "payment-still-confirming",
		children: t(number === void 0 ? `${P}.still_confirming` : `${P}.request.still_confirming`)
	}) : live.finished ? /* @__PURE__ */ jsx("p", {
		role: "status",
		className: "text-label-14 text-ink",
		"data-test": "payment-confirming",
		children: t(`${P}.confirming`)
	}) : windowOver ? /* @__PURE__ */ jsx("p", {
		role: "status",
		className: "text-label-14 text-ink",
		"data-test": "payment-ran-out",
		children: t(`${P}.ran_out`)
	}) : /* @__PURE__ */ jsxs("p", {
		className: "flex items-center gap-2 text-copy-14 text-ink",
		"data-test": "payment-time",
		children: [t(`${P}.time_left`), /* @__PURE__ */ jsx("span", {
			className: "tw-figure text-label-14",
			dir: "ltr",
			"data-test": "payment-time-left",
			children: minutesAndSeconds(left)
		})]
	}), live.frameUrl === null ? null : /* @__PURE__ */ jsx(Dialog, {
		open: live.frameOpen,
		onOpenChange: (open) => open ? void 0 : setLeaving(true),
		children: /* @__PURE__ */ jsxs(DialogContent, {
			className: "top-0 left-0 flex h-dvh max-w-none translate-x-0 translate-y-0 flex-col rounded-none p-4 sm:top-[50%] sm:left-[50%] sm:h-[85vh] sm:max-w-2xl sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-lg",
			"data-test": "payment-frame-dialog",
			children: [
				/* @__PURE__ */ jsxs(DialogHeader, { children: [
					/* @__PURE__ */ jsx(DialogTitle, { children: t(`${P}.frame.title`) }),
					/* @__PURE__ */ jsx(DialogDescription, { children: t(`${P}.frame.body`) }),
					/* @__PURE__ */ jsxs("p", {
						className: "flex items-center gap-2 text-copy-14 text-ink",
						"data-test": "payment-frame-time",
						children: [t(`${P}.time_left`), /* @__PURE__ */ jsx("span", {
							className: "tw-figure text-label-14",
							dir: "ltr",
							children: minutesAndSeconds(left)
						})]
					})
				] }),
				leaving ? /* @__PURE__ */ jsxs("div", {
					role: "alert",
					className: "grid gap-2 rounded-md bg-surface-sunken p-3",
					"data-test": "payment-leave",
					children: [
						/* @__PURE__ */ jsx("p", {
							className: "text-label-14 text-ink",
							children: t(`${P}.frame.leave_title`)
						}),
						/* @__PURE__ */ jsx("p", {
							className: "text-copy-14 text-ink",
							children: t(`${P}.frame.leave_body`, { time: clock(until) })
						}),
						/* @__PURE__ */ jsxs("div", {
							className: "flex flex-wrap gap-2",
							children: [/* @__PURE__ */ jsx(ActionButton, {
								variant: "outline",
								onClick: () => setLeaving(false),
								"data-test": "payment-stay",
								children: t(`${P}.frame.stay`)
							}), /* @__PURE__ */ jsx(ActionButton, {
								variant: "outline",
								onClick: () => {
									setLeaving(false);
									change({ frameOpen: false });
								},
								"data-test": "payment-leave-confirm",
								children: t(`${P}.frame.leave`)
							})]
						})
					]
				}) : null,
				/* @__PURE__ */ jsx("iframe", {
					ref: frame,
					src: live.frameUrl,
					title: t(`${P}.frame.title`),
					className: "min-h-0 w-full flex-1 rounded-md border border-line bg-white",
					"data-test": "payment-frame"
				})
			]
		})
	})] });
}
export { PaymentBlock as default };
