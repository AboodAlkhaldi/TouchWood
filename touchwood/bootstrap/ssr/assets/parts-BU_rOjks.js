import { t as __exportAll } from "./rolldown-runtime-D8jQpDG7.js";
import { n as useTranslator, t as translate } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as Note } from "./Note-BdXDWF9_.js";
import { n as intlLocale, t as figure } from "./digits-D5IxcRsf.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { t as StoreOffBadge } from "./StoreOffBadge-ROBvQU8U.js";
import { n as formatDate } from "./Time-xzOlytHD.js";
import { t as tone } from "./tones-BOBHTOHW.js";
import { t as Money } from "./Money-ygwrzJUU.js";
import { t as StoreFilter } from "./StoreFilter-BJBTaMiU.js";
import { n as MoreButton, t as Guarded } from "./RowMenu-C6DotZSo.js";
import { n as useDebounced, r as useLiveRead, t as readJson } from "./live-read-Dv7wC0si.js";
import { o as plural, r as ltr } from "./checks-laWsOTXB.js";
import { Link, usePage } from "@inertiajs/react";
import { createContext, useContext } from "react";
import { ArrowRight } from "lucide-react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
// resources/js/pages/Pricing/Admin/parts.tsx
var parts_exports = /* @__PURE__ */ __exportAll({
	Guarded: () => Guarded,
	LoadFailed: () => LoadFailed,
	Mark: () => Mark,
	MoreButton: () => MoreButton,
	Off: () => Off,
	OverlapLine: () => OverlapLine,
	PricingWords: () => PricingWords,
	RowPhoto: () => RowPhoto,
	STORE_OWN: () => STORE_OWN,
	Showing: () => Showing,
	StateBadge: () => StateBadge,
	StoreFrame: () => StoreFrame,
	ToArrow: () => ToArrow,
	ltr: () => ltr,
	nameIn: () => nameIn,
	otherName: () => otherName,
	percentOf: () => percentOf,
	plural: () => plural,
	readJson: () => readJson,
	sizeName: () => sizeName,
	useDate: () => useDate,
	useDebounced: () => useDebounced,
	useLiveRead: () => useLiveRead,
	useLocale: () => useLocale,
	useT: () => useT
});
/** Pricing's words when they do not arrive with the page: a product's Stores tab sends them in its data. */
var PricingWords = createContext(null);
/** The page's words, or the section's own where Pricing draws on another module's page. */
function useT() {
	const page = useTranslator();
	const own = useContext(PricingWords);
	return own === null ? page : (key, values = {}) => own[key] === void 0 ? page(key, values) : translate(own, key, values);
}
function useLocale() {
	return usePage().props.locale;
}
/** A name kept in both languages, in the page's. */
function nameIn(locale, ar, en) {
	return (locale === "ar" ? ar || en : en || ar) ?? "";
}
/** The other language's name, under the page's (P1 "Product"). */
function otherName(locale, ar, en) {
	return locale === "ar" ? en : ar;
}
/** A size's values joined - "60 cm · Oak" - or "One Size". */
function sizeName(t, values) {
	return values.length === 0 ? t("pricing::admin.one_size") : values.join(" · ");
}
/** A percentage kept in basis points, as a person reads it: 1250 → "12.5". */
function percentOf(locale, basisPoints) {
	return new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: 2 }).format(basisPoints / 100);
}
/** An offer's or a discount's depth: "−12.5%", or its price / amount (§4.3.1 "Signs and arrows"). */
function Off({ percentBasisPoints, money }) {
	const locale = useLocale();
	if (percentBasisPoints !== null) return /* @__PURE__ */ jsx("bdi", {
		dir: "ltr",
		className: "tw-figure",
		children: `−${percentOf(locale, percentBasisPoints)}%`
	});
	return money === null ? /* @__PURE__ */ jsx(Fragment$1, { children: "—" }) : /* @__PURE__ */ jsx(Money, { value: money });
}
/** A date in a store's zone, as components/Time writes one ("30 Oct 2026"), for a moment inside a sentence. */
function useDate(zone) {
	const locale = useLocale();
	return (iso) => formatDate(iso, zone, locale);
}
/** Another offer or discount in the same dates: "−10% · 60 cm, 80 cm, 1 Nov to 30 Nov". */
function OverlapLine({ description, startsAt, endsAt, alwaysWins, zone }) {
	const t = useT();
	const date = useDate(zone);
	return /* @__PURE__ */ jsxs("span", { children: [t("pricing::admin.note.overlap_line", {
		what: description,
		dates: t("pricing::admin.note.dates", {
			start: date(startsAt),
			end: endsAt === null ? t("pricing::admin.no_end") : date(endsAt)
		})
	}), alwaysWins ? /* @__PURE__ */ jsxs(Fragment$1, { children: [" ", /* @__PURE__ */ jsx(Mark, {
		tone: "amber-subtle",
		children: t("pricing::admin.badge.always_wins")
	})] }) : null] });
}
/** "from → to": Lucide's arrow, mirrored on Arabic pages, read out as "to". */
function ToArrow() {
	const t = useT();
	return /* @__PURE__ */ jsxs(Fragment$1, { children: [/* @__PURE__ */ jsx(ArrowRight, {
		"aria-hidden": "true",
		className: "inline size-3.5 rtl:rotate-180"
	}), /* @__PURE__ */ jsx("span", {
		className: "sr-only",
		children: t("pricing::admin.to")
	})] });
}
/** A mark on a row, in words as well as colour (Geist's Badge). */
function Mark({ tone: name, children, test }) {
	return /* @__PURE__ */ jsx(Badge, {
		className: tone(name),
		"data-test": test,
		children
	});
}
var STATE_TONE = {
	SCHEDULED: "blue-subtle",
	RUNNING: "green-subtle",
	ENDED: "gray-subtle"
};
/** An offer's or a discount's state: Scheduled blue, Running green, Ended gray. */
function StateBadge({ state }) {
	const t = useT();
	return /* @__PURE__ */ jsx(Mark, {
		tone: STATE_TONE[state] ?? "gray-subtle",
		test: "state",
		children: t(`pricing::admin.state.${state}`)
	});
}
/** A row's photo, 40 px; a quiet square without one. */
function RowPhoto({ src }) {
	return src !== null ? /* @__PURE__ */ jsx("img", {
		src,
		alt: "",
		className: "size-10 rounded-md border border-line object-cover"
	}) : /* @__PURE__ */ jsx("span", {
		className: "block size-10 rounded-md bg-surface-sunken",
		"aria-hidden": "true"
	});
}
/**
* The address's values that belong to the store left, dropped with it when another is chosen - as Inventory's
* Stock drops its drawer's size (inventory.md, the review of step 5): Prices' open drawer (`size`), the offer
* Offers names (`sale`), and a Show More cursor (`after`). The search and the filters stay.
*/
var STORE_OWN = [
	"size",
	"sale",
	"after"
];
/**
* What every Pricing screen draws above its rows (§4.3.1, §4.3.2): the store filter (none for one store),
* Off for a Super Admin's off store, the discount ceiling - a link to the settings for whoever may change
* it (Q11) - and a wired store's Note.
*/
function StoreFrame({ frame, wiredBody }) {
	const t = useT();
	const ceiling = t("pricing::admin.ceiling", { percent: frame.ceilingPercent });
	return /* @__PURE__ */ jsxs("div", {
		className: "grid gap-3",
		children: [
			/* @__PURE__ */ jsxs("div", {
				className: "flex flex-wrap items-end gap-3",
				children: [/* @__PURE__ */ jsx(StoreFilter, {
					stores: frame.stores,
					value: frame.storeCode,
					drop: STORE_OWN,
					className: "max-w-xs"
				}), frame.off ? /* @__PURE__ */ jsx(StoreOffBadge, { className: "mb-2" }) : null]
			}),
			/* @__PURE__ */ jsx("p", {
				className: "text-copy-13 text-ink-muted",
				"data-test": "ceiling",
				children: frame.maySetCeiling ? /* @__PURE__ */ jsx(Link, {
					href: `/admin/settings?store=${frame.storeCode}`,
					className: "underline underline-offset-4 hover:text-ink",
					children: ceiling
				}) : ceiling
			}),
			frame.wired ? /* @__PURE__ */ jsx(Note, {
				label: t("pricing::admin.wired.label"),
				"data-test": "wired",
				children: wiredBody ?? t("pricing::admin.wired.body")
			}) : null
		]
	});
}
/** "Showing 50 of 312" (Q6), above a list. */
function Showing({ shown, total }) {
	const t = useT();
	const locale = useLocale();
	return /* @__PURE__ */ jsx("p", {
		className: "tw-figure text-copy-13 text-ink-muted",
		"aria-live": "polite",
		"data-test": "showing",
		children: t("pricing::admin.showing", {
			shown: figure(locale, shown),
			total: figure(locale, total)
		})
	});
}
/** A read that failed, said in words with its way to try again (the review of step 3). */
function LoadFailed({ what, onRetry }) {
	const t = useT();
	return /* @__PURE__ */ jsx(Note, {
		variant: "error",
		size: "small",
		alert: true,
		action: /* @__PURE__ */ jsx(Button, {
			type: "button",
			variant: "outline",
			size: "sm",
			onClick: onRetry,
			"data-test": "load-retry",
			children: t("pricing::admin.failed.try_again")
		}),
		"data-test": `load-failed-${what}`,
		children: t(`pricing::admin.failed.${what}`)
	});
}
export { useLocale as _, PricingWords as a, StateBadge as c, nameIn as d, otherName as f, useDate as g, sizeName as h, OverlapLine as i, StoreFrame as l, percentOf as m, Mark as n, RowPhoto as o, parts_exports as p, Off as r, Showing as s, LoadFailed as t, ToArrow as u, useT as v };
