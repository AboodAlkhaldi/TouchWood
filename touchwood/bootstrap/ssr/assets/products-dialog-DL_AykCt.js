import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as Spinner } from "./spinner-C8hxoQvt.js";
import { t as Input } from "./input-DlWSmFH_.js";
import { t as PanelDialog } from "./PanelDialog-BPuxGGA-.js";
import { t as readJson } from "./live-read-Dv7wC0si.js";
import { F as useInLanguage } from "../ssr.js";
import { t as latestReads } from "./latest-read-Co0oih-o.js";
import { useEffect, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/products-dialog.tsx
function ProductDialog({ search, store, chosen, max, onAdd, onClose }) {
	const t = useTranslator();
	const name = useInLanguage();
	const [query, setQuery] = useState("");
	const [found, setFound] = useState(null);
	const [next, setNext] = useState(null);
	const [failed, setFailed] = useState(false);
	const [loading, setLoading] = useState(false);
	const latest = useRef(latestReads()).current;
	const full = chosen.length >= max;
	const read = (typed, after, signal) => {
		const mine = latest.start();
		setLoading(true);
		return readJson(search, {
			store,
			q: typed,
			after
		}, signal).then((answer) => {
			if (signal?.aborted || !mine()) return;
			setLoading(false);
			setFailed(answer === null);
			if (answer !== null) {
				setFound((was) => after === null ? answer.products : [...was ?? [], ...answer.products]);
				setNext(answer.next);
			}
		});
	};
	useEffect(() => {
		const typed = query.trim();
		if (typed === "") {
			latest.drop();
			setFound(null);
			setNext(null);
			setLoading(false);
			return;
		}
		const controller = new AbortController();
		const wait = setTimeout(() => void read(typed, null, controller.signal), 300);
		return () => {
			clearTimeout(wait);
			controller.abort();
		};
	}, [
		query,
		search,
		store
	]);
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		title: t("content::admin_documents.products.title"),
		description: full ? t("content::admin_documents.products.full", { max }) : t("content::admin_documents.products.empty"),
		busy: false,
		cancelLabel: t("content::admin_documents.products.close"),
		confirm: null,
		children: /* @__PURE__ */ jsxs("div", {
			className: "grid gap-3",
			children: [
				/* @__PURE__ */ jsx(Input, {
					type: "search",
					"aria-label": t("content::admin_documents.products.search"),
					placeholder: t("content::admin_documents.products.search"),
					value: query,
					onChange: (event) => setQuery(event.target.value),
					"data-test": "product-search"
				}),
				loading ? /* @__PURE__ */ jsx(Spinner, {}) : null,
				failed ? /* @__PURE__ */ jsx("p", {
					className: "text-copy-14 text-bad",
					children: t("content::admin_documents.products.failed")
				}) : null,
				found !== null && found.length === 0 ? /* @__PURE__ */ jsx("p", {
					className: "text-copy-14 text-ink-muted",
					children: t("content::admin_documents.products.none", { query: query.trim() })
				}) : null,
				/* @__PURE__ */ jsx("ul", {
					className: "grid max-h-72 gap-1 overflow-y-auto",
					children: (found ?? []).map((product) => {
						const added = chosen.some((each) => each.id === product.id);
						return /* @__PURE__ */ jsxs("li", {
							className: "flex min-w-0 items-center justify-between gap-2",
							children: [/* @__PURE__ */ jsx("span", {
								className: "truncate text-copy-14 text-ink",
								children: name(product.nameAr, product.nameEn ?? product.nameAr)
							}), /* @__PURE__ */ jsx(Button, {
								type: "button",
								size: "sm",
								variant: "outline",
								disabled: added || full,
								onClick: () => onAdd(product),
								"data-test": `choose-product-${product.id}`,
								children: added ? t("content::admin_documents.products.added") : t("content::admin_documents.products.add")
							})]
						}, product.id);
					})
				}),
				next !== null ? /* @__PURE__ */ jsx(Button, {
					type: "button",
					variant: "outline",
					size: "sm",
					disabled: loading,
					onClick: () => void read(query.trim(), next),
					"data-test": "product-more",
					children: t("content::admin_documents.products.more")
				}) : null
			]
		})
	});
}
export { ProductDialog };
