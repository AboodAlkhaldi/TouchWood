import { t as cn } from "./cn-C2NdifnB.js";
import { n as intlLocale } from "./digits-D5IxcRsf.js";
import { usePage } from "@inertiajs/react";
import { useId } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
import { HoverCard } from "radix-ui";
// resources/js/components/ui/hover-card.tsx
function HoverCard$1({ ...props }) {
	return /* @__PURE__ */ jsx(HoverCard.Root, {
		"data-slot": "hover-card",
		...props
	});
}
function HoverCardTrigger({ ...props }) {
	return /* @__PURE__ */ jsx(HoverCard.Trigger, {
		"data-slot": "hover-card-trigger",
		...props
	});
}
function HoverCardContent({ className, align = "center", sideOffset = 4, ...props }) {
	return /* @__PURE__ */ jsx(HoverCard.Portal, {
		"data-slot": "hover-card-portal",
		children: /* @__PURE__ */ jsx(HoverCard.Content, {
			"data-slot": "hover-card-content",
			align,
			sideOffset,
			className: cn("z-50 w-64 origin-(--radix-hover-card-content-transform-origin) rounded-md border bg-popover p-4 text-popover-foreground shadow-md outline-hidden data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95", className),
			...props
		})
	});
}
// resources/js/components/Time.tsx
var WEEK_MS = 6048e5;
/**
* The zone the page writes its moments in (frontend.md §1.10): the store a panel screen shows
* (`storeTimezone`, its own page value), else the panel's base store (`panelTimezone`, shared), else
* the shop's store. A page with none - nothing signed in, no store - writes UTC, and says so beside
* the time.
*/
function useStoreZone() {
	const { storeTimezone, panelTimezone, shop } = usePage().props;
	return storeTimezone ?? panelTimezone ?? shop?.timezone ?? "UTC";
}
/**
* The same writing for a moment inside a sentence ("Trusted until :date"), where a component
* cannot go: the full moment, or its date alone, in the store's zone and Latin digits.
*/
function useMoments() {
	const { locale } = usePage().props;
	const zone = useStoreZone();
	return {
		full: (iso) => formatMoment(iso, zone, locale),
		date: (iso) => formatDate(iso, zone, locale)
	};
}
/** Intl's tag for the page's language, always with Latin digits (lib/digits.ts). */
function language(locale) {
	return intlLocale(locale);
}
/**
* A moment as the server sent it, read as the instant it is. The server and the database keep UTC
* (owner), and their strings come in several shapes - ATOM ("…T05:00:00+00:00"), PostgreSQL's own
* ("… 05:00:00.123456+00"), a bare date - so each is brought to one JavaScript can read; one with
* no offset at all is UTC.
*/
function instant(value) {
	let text = value.trim();
	if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return /* @__PURE__ */ new Date(`${text}T00:00:00Z`);
	text = text.replace(" ", "T").replace(/(\.\d{3})\d+/, "$1").replace(/([+-]\d{2})$/, "$1:00").replace(/([+-]\d{2})(\d{2})$/, "$1:$2");
	return new Date(/(Z|[+-]\d{2}:\d{2})$/.test(text) ? text : `${text}Z`);
}
function formatMoment(iso, zone, locale) {
	const at = instant(iso);
	if (Number.isNaN(at.getTime())) return iso;
	return new Intl.DateTimeFormat(language(locale), {
		year: "numeric",
		month: "short",
		day: "numeric",
		hour: "numeric",
		minute: "2-digit",
		timeZone: zone,
		timeZoneName: "short"
	}).format(at);
}
function formatDate(iso, zone, locale) {
	const at = instant(iso);
	return Number.isNaN(at.getTime()) ? iso : new Intl.DateTimeFormat(language(locale), {
		year: "numeric",
		month: "short",
		day: "numeric",
		timeZone: zone
	}).format(at);
}
/**
* Geist's short form: "2m ago", "5h ago", "Yesterday" - Intl's narrow style, which writes exactly
* that in English, with the first letter raised as Geist writes it on its own; inside a sentence
* ("Joined yesterday") it stays as Intl wrote it. Arabic is Intl's own.
*/
function formatRelative(iso, locale, now, inSentence) {
	const then = instant(iso).getTime();
	const diff = then - now;
	if (Number.isNaN(then) || Math.abs(diff) >= WEEK_MS) return null;
	const words = new Intl.RelativeTimeFormat(language(locale), {
		numeric: "auto",
		style: "narrow"
	});
	const minutes = Math.round(diff / 6e4);
	const hours = Math.round(minutes / 60);
	const text = Math.abs(minutes) < 60 ? words.format(minutes, "minute") : Math.abs(hours) < 24 ? words.format(hours, "hour") : words.format(Math.round(hours / 24), "day");
	return locale !== "ar" && !inSentence ? text.charAt(0).toUpperCase() + text.slice(1) : text;
}
function Time({ value, mode = "relative", focusable = true, inSentence = false, className, ...rest }) {
	const { locale } = usePage().props;
	const zone = useStoreZone();
	const described = useId();
	const full = formatMoment(value, zone, locale);
	if (mode === "absolute") return /* @__PURE__ */ jsx("time", {
		...rest,
		dateTime: value,
		className: cn("tw-figure", className),
		children: full
	});
	const short = formatRelative(value, locale, Date.now(), inSentence) ?? formatDate(value, zone, locale);
	const utc = zone === "UTC" ? null : formatMoment(value, "UTC", locale);
	return /* @__PURE__ */ jsxs(HoverCard$1, {
		openDelay: 150,
		closeDelay: 100,
		children: [
			/* @__PURE__ */ jsx(HoverCardTrigger, {
				asChild: true,
				children: /* @__PURE__ */ jsx("time", {
					...rest,
					dateTime: value,
					tabIndex: focusable ? 0 : void 0,
					"aria-describedby": described,
					suppressHydrationWarning: true,
					className: cn("tw-figure cursor-default underline decoration-dotted decoration-line-strong underline-offset-4", className),
					children: short
				})
			}),
			/* @__PURE__ */ jsxs(HoverCardContent, {
				className: "material-menu w-auto border-0 px-3 py-2 text-copy-13",
				"data-test": "time-card",
				children: [/* @__PURE__ */ jsx("p", {
					className: "tw-figure text-ink",
					children: full
				}), utc === null ? null : /* @__PURE__ */ jsx("p", {
					className: "tw-figure text-ink-muted",
					children: utc
				})]
			}),
			/* @__PURE__ */ jsx("span", {
				id: described,
				hidden: true,
				children: utc === null ? full : `${full} · ${utc}`
			})
		]
	});
}
export { useStoreZone as a, useMoments as i, formatDate as n, formatMoment as r, Time as t };
