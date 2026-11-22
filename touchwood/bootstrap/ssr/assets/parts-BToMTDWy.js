import { t as __exportAll } from "./rolldown-runtime-D8jQpDG7.js";
import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { n as AvatarFallback, r as AvatarImage, t as Avatar } from "./avatar-fynEOmPb.js";
import { t as Switch } from "./switch-DssMSzdc.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
// resources/js/lib/initials.ts
/**
* One or two letters of a name, as Geist's Avatar takes them: the first letter of its first two
* words ("Sara Ali" → "SA"). The lists show initials, not pictures: resolving a picture per row
* would be a call to Platform per person (owner, 2026-09-23).
*/
function initials(name) {
	return name.split(/\s+/).filter((word) => word !== "").slice(0, 2).map((word) => word.slice(0, 1).toLocaleUpperCase()).join("");
}
// resources/js/pages/Shipping/Admin/Carriers/parts.tsx
var parts_exports = /* @__PURE__ */ __exportAll({
	C: () => C,
	CarrierLogo: () => CarrierLogo,
	CarrierSwitch: () => CarrierSwitch,
	LIST: () => LIST,
	trackingExample: () => trackingExample,
	trackingProblem: () => trackingProblem,
	useCarrierName: () => useCarrierName,
	useOtherName: () => useOtherName
});
var C = "shipping::admin_carriers";
var LIST = "/admin/shipping/carriers";
/** The carrier's name in the page's language, the other language's when it has none. */
function useCarrierName() {
	const { locale } = usePage().props;
	return (carrier) => locale === "ar" ? carrier.nameAr || carrier.nameEn : carrier.nameEn || carrier.nameAr;
}
/** The other language's name, shown under the page's (frontend.md §1.11). */
function useOtherName() {
	const { locale } = usePage().props;
	return (carrier) => locale === "ar" ? carrier.nameEn : carrier.nameAr;
}
/** Its logo's thumbnail, else its initials in a circle (Q15) - shadcn's Avatar and its fallback. */
function CarrierLogo({ carrier, name, size = "size-8" }) {
	return /* @__PURE__ */ jsxs(Avatar, {
		className: `${size} rounded-md border border-line`,
		"data-test": `carrier-logo-${carrier.id}`,
		children: [carrier.logo !== null ? /* @__PURE__ */ jsx(AvatarImage, {
			src: carrier.logo,
			alt: "",
			className: "object-contain"
		}) : null, /* @__PURE__ */ jsx(AvatarFallback, {
			"aria-hidden": "true",
			className: "rounded-md text-label-12",
			children: initials(name)
		})]
	});
}
/** Where a tracking number opens with this pattern, or null when the pattern cannot make a link (Q8). */
function trackingExample(pattern) {
	const trimmed = pattern.trim();
	return /^https:\/\//i.test(trimmed) && trimmed.split("{number}").length === 2 ? trimmed.replace("{number}", "12345") : null;
}
/** What is wrong with a pattern as typed, in words - null when it is right or empty (§1.3: https, one {number}). */
function trackingProblem(pattern, t) {
	const trimmed = pattern.trim();
	if (trimmed === "") return null;
	if (!/^https:\/\//i.test(trimmed)) return t(`${C}.tracking.https`);
	return trimmed.split("{number}").length === 2 ? null : t(`${C}.tracking.number`);
}
/**
* The on/off switch (Q5): switching on is saved at once; switching off asks first, saying it leaves every
* new checkout and that placed orders keep it.
*/
function CarrierSwitch({ carrier, name, storeCode, test }) {
	const t = useTranslator();
	const [asking, setAsking] = useState(false);
	const [busy, setBusy] = useState(false);
	const send = (active) => router.post(`${LIST}/${carrier.id}/switch`, {
		store: storeCode,
		active
	}, {
		preserveScroll: true,
		onStart: () => setBusy(true),
		onFinish: () => setBusy(false),
		onSuccess: () => setAsking(false)
	});
	return /* @__PURE__ */ jsxs(Fragment$1, { children: [/* @__PURE__ */ jsx(Switch, {
		checked: carrier.active,
		disabled: busy,
		onCheckedChange: (on) => on ? send(true) : setAsking(true),
		"aria-label": t(`${C}.switch.label`, { name }),
		className: "data-[state=unchecked]:bg-ink-subtle",
		"data-test": test ?? `carrier-switch-${carrier.id}`
	}), /* @__PURE__ */ jsx(PanelDialog, {
		open: asking,
		onOpenChange: setAsking,
		title: t(`${C}.switch.off_title`, { name }),
		description: t(`${C}.switch.off_body`),
		busy,
		confirm: /* @__PURE__ */ jsx(ActionButton, {
			loading: busy,
			onClick: () => send(false),
			"data-test": "confirm-carrier-off",
			children: t(`${C}.switch.off_confirm`)
		}),
		children: null
	})] });
}
export { parts_exports as a, useCarrierName as c, LIST as i, useOtherName as l, CarrierLogo as n, trackingExample as o, CarrierSwitch as r, trackingProblem as s, C as t, initials as u };
