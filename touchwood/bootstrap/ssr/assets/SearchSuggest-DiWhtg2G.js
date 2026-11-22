import { a as CommandItem, i as CommandInput, o as CommandList, r as CommandGroup, t as Command } from "./command-D8WAkiWr.js";
import { n as PopoverAnchor, r as PopoverContent, t as Popover } from "./popover-BbvwzNo6.js";
import { H as useLink, L as ShopPhoto } from "../ssr.js";
import { router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Search } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/shop/SearchSuggest.tsx
var ALL = "__all";
var PAUSE = 250;
function SearchSuggest({ words, typed: initial }) {
	const link = useLink();
	const [text, setText] = useState(initial);
	const [open, setOpen] = useState(true);
	const [cards, setCards] = useState([]);
	const [selected, setSelected] = useState(ALL);
	const anchor = useRef(null);
	const typed = text.trim();
	const long = [...typed].length >= 2;
	useEffect(() => {
		if (!long) {
			setCards([]);
			return;
		}
		const controller = new AbortController();
		const timer = window.setTimeout(() => {
			fetch(link("storefront.catalog.suggest", { q: typed }), {
				headers: { Accept: "application/json" },
				credentials: "same-origin",
				signal: controller.signal
			}).then((response) => response.ok ? response.json() : { products: [] }).then((body) => {
				setCards(body.products);
				setSelected(ALL);
			}).catch(() => void 0);
		}, PAUSE);
		return () => {
			window.clearTimeout(timer);
			controller.abort();
		};
	}, [typed, long]);
	function go(href) {
		setOpen(false);
		router.visit(href);
	}
	return /* @__PURE__ */ jsx(Popover, {
		open: open && long,
		onOpenChange: setOpen,
		children: /* @__PURE__ */ jsxs(Command, {
			shouldFilter: false,
			value: selected,
			onValueChange: setSelected,
			label: words.search,
			className: "material-small h-auto w-full overflow-visible bg-surface **:data-[slot=command-input-wrapper]:border-b-0",
			"data-test": "search-box",
			children: [/* @__PURE__ */ jsx(PopoverAnchor, {
				asChild: true,
				children: /* @__PURE__ */ jsx("div", {
					ref: anchor,
					role: "search",
					children: /* @__PURE__ */ jsx(CommandInput, {
						value: text,
						onValueChange: (value) => {
							setText(value);
							setOpen(true);
						},
						onFocus: () => setOpen(true),
						autoFocus: true,
						placeholder: words.search_placeholder,
						"aria-label": words.search,
						"data-test": "search-input"
					})
				})
			}), /* @__PURE__ */ jsx(PopoverContent, {
				align: "start",
				className: "w-(--radix-popover-trigger-width) min-w-72 p-0",
				onOpenAutoFocus: (event) => event.preventDefault(),
				onInteractOutside: (event) => {
					if (anchor.current?.contains(event.target)) event.preventDefault();
				},
				"data-test": "search-suggestions",
				children: /* @__PURE__ */ jsxs(CommandList, { children: [cards.length === 0 ? /* @__PURE__ */ jsx("p", {
					className: "px-3 py-3 text-copy-14 text-ink-muted",
					children: words.no_suggestions
				}) : null, /* @__PURE__ */ jsxs(CommandGroup, { children: [cards.map((card) => /* @__PURE__ */ jsxs(CommandItem, {
					value: card.productId,
					onSelect: () => go(link("storefront.catalog.product", { slug: card.slug })),
					"data-test": "suggestion",
					children: [/* @__PURE__ */ jsx(ShopPhoto, {
						photo: card.photo,
						size: "thumb",
						alt: "",
						className: "size-8 shrink-0 rounded"
					}), /* @__PURE__ */ jsx("span", {
						className: "truncate",
						children: card.name
					})]
				}, card.productId)), /* @__PURE__ */ jsxs(CommandItem, {
					value: ALL,
					onSelect: () => go(link("storefront.catalog.search", { q: typed })),
					"data-test": "see-all",
					children: [/* @__PURE__ */ jsx(Search, { "aria-hidden": "true" }), words.see_all]
				})] })] })
			})]
		})
	});
}
export { SearchSuggest };
