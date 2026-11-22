import { t as Button } from "./button-DoWvyMdE.js";
import { a as DialogFooter, i as DialogDescription, o as DialogHeader, r as DialogContent, s as DialogTitle, t as Dialog } from "./dialog-C7NRbZ1B.js";
import { $ as SheetContent, B as ContentLink, L as ShopPhoto, Q as Sheet, V as shopHref, et as SheetDescription, nt as SheetHeader, rt as SheetTitle, tt as SheetFooter } from "../ssr.js";
import { usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/ShopPopup.tsx
/** The pop-ups this browser has shown: one key, their ids, the last 50. */
var SEEN = "tw:popups";
function ShopPopup({ popup }) {
	const { shop, locale } = usePage().props;
	const [open, setOpen] = useState(false);
	useEffect(() => {
		let seen;
		try {
			seen = JSON.parse(window.localStorage.getItem(SEEN) ?? "[]");
		} catch {
			return;
		}
		const ids = Array.isArray(seen) ? seen.filter((id) => typeof id === "string") : [];
		if (ids.includes(popup.id)) return;
		const timer = window.setTimeout(() => {
			try {
				window.localStorage.setItem(SEEN, JSON.stringify([...ids.slice(-49), popup.id]));
			} catch {
				return;
			}
			setOpen(true);
		}, popup.delaySeconds * 1e3);
		return () => window.clearTimeout(timer);
	}, [popup.id, popup.delaySeconds]);
	if (!open || shop === null || shop === void 0) return null;
	const button = popup.button !== null && shopHref(popup.link, shop.code, locale) !== null ? /* @__PURE__ */ jsx(Button, {
		asChild: true,
		onClick: () => setOpen(false),
		children: /* @__PURE__ */ jsx(ContentLink, {
			link: popup.link,
			store: shop.code,
			locale,
			test: "popup-button",
			children: popup.button
		})
	}) : null;
	if (window.matchMedia("(max-width: 640px)").matches) return /* @__PURE__ */ jsx(Sheet, {
		open: true,
		onOpenChange: setOpen,
		children: /* @__PURE__ */ jsxs(SheetContent, {
			side: "bottom",
			className: "max-h-[40vh] gap-3 overflow-y-auto rounded-t-xl",
			"data-test": "popup",
			"data-kind": "sheet",
			children: [/* @__PURE__ */ jsxs(SheetHeader, {
				className: "pe-10",
				children: [/* @__PURE__ */ jsx(SheetTitle, { children: popup.title }), /* @__PURE__ */ jsx(SheetDescription, {
					className: "text-copy-14 whitespace-pre-line text-ink",
					children: popup.text
				})]
			}), button === null ? null : /* @__PURE__ */ jsx(SheetFooter, {
				className: "pt-0",
				children: button
			})]
		})
	});
	return /* @__PURE__ */ jsx(Dialog, {
		open: true,
		onOpenChange: setOpen,
		children: /* @__PURE__ */ jsxs(DialogContent, {
			className: "max-w-[420px] sm:max-w-[420px]",
			"data-test": "popup",
			"data-kind": "dialog",
			children: [
				Object.keys(popup.image).length === 0 ? null : /* @__PURE__ */ jsx(ShopPhoto, {
					photo: popup.image,
					size: "card",
					alt: "",
					className: "aspect-video w-full rounded-md"
				}),
				/* @__PURE__ */ jsxs(DialogHeader, { children: [/* @__PURE__ */ jsx(DialogTitle, { children: popup.title }), /* @__PURE__ */ jsx(DialogDescription, {
					className: "text-copy-14 whitespace-pre-line text-ink",
					children: popup.text
				})] }),
				button === null ? null : /* @__PURE__ */ jsx(DialogFooter, { children: button })
			]
		})
	});
}
export { ShopPopup as default };
