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
// resources/js/components/content/target-picker.tsx
function TargetPicker({ run, kind, title, chosen, onPick, onClose }) {
	const t = useTranslator();
	const name = useInLanguage();
	const whole = kind === "CATEGORY" || kind === "BRAND";
	const [query, setQuery] = useState("");
	const [found, setFound] = useState(null);
	const [next, setNext] = useState(null);
	const [failed, setFailed] = useState(false);
	const [loading, setLoading] = useState(false);
	const latest = useRef(latestReads()).current;
	const read = (typed, after, signal) => {
		const mine = latest.start();
		setLoading(true);
		return readJson("/admin/content/campaigns/targets", {
			run,
			kind,
			q: typed,
			after
		}, signal).then((answer) => {
			if (signal?.aborted || !mine()) return;
			setLoading(false);
			setFailed(answer === null);
			if (answer !== null) {
				setFound((was) => after === null ? answer.targets : [...was ?? [], ...answer.targets]);
				setNext(answer.next);
			}
		});
	};
	useEffect(() => {
		const typed = query.trim();
		if (!whole && typed === "") {
			latest.drop();
			setFound(null);
			setNext(null);
			setLoading(false);
			return;
		}
		const controller = new AbortController();
		const wait = setTimeout(() => void read(typed, null, controller.signal), whole && typed === "" ? 0 : 300);
		return () => {
			clearTimeout(wait);
			controller.abort();
		};
	}, [
		query,
		run,
		kind,
		whole
	]);
	const shown = found ?? [];
	return /* @__PURE__ */ jsx(PanelDialog, {
		open: true,
		onOpenChange: (open) => open ? void 0 : onClose(),
		title,
		description: t("content::admin_campaigns.prices.searching", { kind: t(`content::admin_campaigns.prices.kind.${kind}`) }),
		busy: false,
		cancelLabel: t("content::admin_campaigns.prices.done"),
		confirm: null,
		children: /* @__PURE__ */ jsxs("div", {
			className: "grid gap-3",
			children: [
				/* @__PURE__ */ jsx(Input, {
					type: "search",
					"aria-label": t("content::admin_campaigns.prices.search"),
					placeholder: t("content::admin_campaigns.prices.search"),
					value: query,
					onChange: (event) => setQuery(event.target.value),
					"data-test": "target-search"
				}),
				loading ? /* @__PURE__ */ jsx(Spinner, {}) : null,
				failed ? /* @__PURE__ */ jsx("p", {
					className: "text-copy-14 text-bad",
					children: t("content::admin_campaigns.prices.search_failed")
				}) : null,
				found !== null && shown.length === 0 && query.trim() !== "" ? /* @__PURE__ */ jsx("p", {
					className: "text-copy-14 text-ink-muted",
					children: t("content::admin_campaigns.prices.none_match", { query: query.trim() })
				}) : null,
				/* @__PURE__ */ jsx("ul", {
					className: "grid max-h-72 gap-1 overflow-y-auto",
					children: shown.map((each) => {
						const added = chosen.includes(each.id);
						return /* @__PURE__ */ jsxs("li", {
							className: "flex min-w-0 items-center justify-between gap-2",
							children: [/* @__PURE__ */ jsxs("span", {
								className: "grid min-w-0",
								children: [/* @__PURE__ */ jsx("span", {
									className: "truncate text-copy-14 text-ink",
									children: name(each.nameAr, each.nameEn ?? each.nameAr)
								}), each.detail === null || each.detail === "" ? null : /* @__PURE__ */ jsx("bdi", {
									dir: "ltr",
									className: "truncate text-copy-13 text-ink-muted",
									children: each.detail
								})]
							}), /* @__PURE__ */ jsx(Button, {
								type: "button",
								size: "sm",
								variant: "outline",
								disabled: added,
								onClick: () => onPick(each),
								"data-test": `pick-${each.id}`,
								children: added ? t("content::admin_campaigns.prices.picked") : t("content::admin_campaigns.prices.pick")
							})]
						}, each.id);
					})
				}),
				next !== null ? /* @__PURE__ */ jsx(Button, {
					type: "button",
					variant: "outline",
					size: "sm",
					disabled: loading,
					onClick: () => void read(query.trim(), next),
					"data-test": "target-more",
					children: t("content::admin_campaigns.prices.more")
				}) : null
			]
		})
	});
}
export { TargetPicker };
