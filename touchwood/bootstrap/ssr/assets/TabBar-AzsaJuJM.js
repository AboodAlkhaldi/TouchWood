import { t as figure } from "./digits-D5IxcRsf.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { H as useLink } from "../ssr.js";
import { Link } from "@inertiajs/react";
import { Heart, Search, ShoppingCart, UserRound } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/TabBar.tsx
function TabBar({ House, shop, locale, url, words, sales, staffView }) {
	const link = useLink();
	const path = url.split("?")[0] ?? url;
	const base = `/${shop}/${locale}`;
	const tab = "flex h-14 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] leading-none text-ink aria-[current=page]:font-semibold aria-[current=page]:text-brand";
	const on = (href, exact = false) => exact ? path === href : path === href || path.startsWith(`${href}/`);
	const icon = (active) => active ? "size-6 fill-current" : "size-6";
	const account = link("storefront.account");
	const favourites = link("storefront.feedback.wishlist");
	const inAccount = on(account) && !on(favourites);
	function explore() {
		const box = document.querySelector("[data-test=\"search-row\"] input");
		box?.scrollIntoView({ block: "center" });
		box?.focus();
	}
	return /* @__PURE__ */ jsx("nav", {
		"aria-label": words.tabs,
		className: "fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] sm:hidden",
		"data-test": "tab-bar",
		children: /* @__PURE__ */ jsxs("div", {
			className: "flex",
			children: [
				/* @__PURE__ */ jsxs(Link, {
					href: base,
					className: tab,
					"aria-current": on(base, true) ? "page" : void 0,
					"data-test": "tab-home",
					children: [/* @__PURE__ */ jsx(House, {
						"aria-hidden": "true",
						className: icon(on(base, true))
					}), words.home]
				}),
				/* @__PURE__ */ jsxs("button", {
					type: "button",
					className: tab,
					onClick: explore,
					"data-test": "tab-explore",
					children: [/* @__PURE__ */ jsx(Search, {
						"aria-hidden": "true",
						className: icon(on(`${base}/search`))
					}), words.explore]
				}),
				sales ? /* @__PURE__ */ jsxs(Link, {
					href: `${base}/cart`,
					className: tab,
					"aria-current": on(`${base}/cart`) ? "page" : void 0,
					"data-test": "tab-cart",
					children: [/* @__PURE__ */ jsxs("span", {
						className: "relative",
						children: [/* @__PURE__ */ jsx(ShoppingCart, {
							"aria-hidden": "true",
							className: icon(on(`${base}/cart`))
						}), sales.products > 0 ? /* @__PURE__ */ jsx(Badge, {
							className: "tw-figure absolute -end-2 -top-1.5 h-4 min-w-4 rounded-full bg-accent px-1 text-[10px] leading-none text-ink-on-brand",
							"data-test": "tab-cart-count",
							children: sales.products > 99 ? "99+" : figure(locale, sales.products)
						}) : null]
					}), words.cart]
				}) : null,
				staffView ? null : /* @__PURE__ */ jsxs(Link, {
					href: favourites,
					className: tab,
					"aria-current": on(favourites) ? "page" : void 0,
					"data-test": "tab-favourites",
					children: [/* @__PURE__ */ jsx(Heart, {
						"aria-hidden": "true",
						className: icon(on(favourites))
					}), words.favourites]
				}),
				/* @__PURE__ */ jsxs(Link, {
					href: account,
					className: tab,
					"aria-current": inAccount ? "page" : void 0,
					"data-test": "tab-account",
					children: [/* @__PURE__ */ jsx(UserRound, {
						"aria-hidden": "true",
						className: icon(inAccount)
					}), words.account]
				})
			]
		})
	});
}
export { TabBar as default };
