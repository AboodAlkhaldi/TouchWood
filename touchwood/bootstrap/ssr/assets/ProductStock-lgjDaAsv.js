import { r as DropdownMenuContent, t as DropdownMenu, u as DropdownMenuTrigger } from "./dropdown-menu-Bw9NuOcA.js";
import { n as FieldContent, o as FieldLabel, r as FieldDescription, t as Field } from "./field-Ce02Vx8L.js";
import { t as figure } from "./digits-D5IxcRsf.js";
import { t as Switch } from "./switch-DssMSzdc.js";
import { a as TableHead, i as TableCell, n as TableBody, o as TableHeader, r as TableCaption, s as TableRow, t as Table } from "./table-D4bMO-f5.js";
import { n as MoreButton, t as Guarded } from "./RowMenu-C6DotZSo.js";
import { A as useLocale, D as InventoryWords, E as InStock, O as StockBadge, T as useCounts, j as useT, k as threshold, w as CountBox } from "../ssr.js";
import { router, usePage } from "@inertiajs/react";
import { Suspense, lazy, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/sections/Inventory/ProductStock.tsx
var AdjustDialog = lazy(() => import("../ssr.js").then((n) => n.C).then((module) => ({ default: module.AdjustDialog })));
var ThresholdDialog = lazy(() => import("../ssr.js").then((n) => n.C).then((module) => ({ default: module.ThresholdDialog })));
function ProductStock({ data, store }) {
	const card = data;
	return /* @__PURE__ */ jsx(InventoryWords.Provider, {
		value: card.words,
		children: /* @__PURE__ */ jsx(Section, {
			card,
			store: {
				code: store.code,
				name: store.name
			}
		})
	});
}
function sizeLabel(locale, oneSize, values) {
	return values.length === 0 ? oneSize : values.map((value) => locale === "ar" ? value.nameAr : value.nameEn).join(" · ");
}
function Section({ card, store }) {
	const t = useT();
	const locale = useLocale();
	const host = usePage().props;
	const [dialog, setDialog] = useState(null);
	const opener = useRef(null);
	const variants = new Map((host.variants ?? []).map((variant) => [variant.id, variant]));
	const nameOf = (id) => sizeLabel(locale, t("inventory::admin_stock.one_size"), variants.get(id)?.values ?? []);
	const allArchived = card.sizes.length > 0 && card.sizes.every((size) => size.archived);
	const countable = !card.wired;
	const wiredReason = t("inventory::admin_stock.reasons.wired");
	const archivedReason = t("inventory::admin_stock.reasons.archived");
	const counts = useCounts(countable ? card.sizes.filter((size) => !size.archived).map((size) => ({
		id: size.variantId,
		inStock: size.figures.inStock,
		held: size.figures.held,
		holdingOrders: size.figures.holdingOrders
	})) : [], {
		store: store.code,
		prefix: `count-${store.code}`
	});
	return /* @__PURE__ */ jsxs("div", {
		className: "grid gap-4",
		"data-test": `inventory-section-${store.code}`,
		children: [
			/* @__PURE__ */ jsx("h3", {
				className: "text-heading-14 text-ink",
				children: t("inventory::admin_stock.section.title")
			}),
			/* @__PURE__ */ jsxs(Field, {
				orientation: "horizontal",
				"data-disabled": card.lastPieces === null || allArchived || void 0,
				children: [/* @__PURE__ */ jsx(Switch, {
					id: `last-pieces-${store.code}`,
					checked: card.lastPieces === true,
					disabled: card.lastPieces === null || allArchived,
					onCheckedChange: (on) => router.post("/admin/stock/last-pieces", {
						store: store.code,
						product: host.product?.id ?? "",
						on
					}, { preserveScroll: true }),
					"aria-describedby": `last-pieces-${store.code}-helper`,
					"data-test": `last-pieces-${store.code}`
				}), /* @__PURE__ */ jsxs(FieldContent, { children: [/* @__PURE__ */ jsx(FieldLabel, {
					htmlFor: `last-pieces-${store.code}`,
					children: t("inventory::admin_stock.section.last_pieces")
				}), /* @__PURE__ */ jsx(FieldDescription, {
					id: `last-pieces-${store.code}-helper`,
					children: card.lastPieces === null ? wiredReason : allArchived ? archivedReason : t("inventory::admin_stock.section.last_pieces_helper")
				})] })]
			}),
			/* @__PURE__ */ jsx("div", {
				className: "overflow-x-auto",
				children: /* @__PURE__ */ jsxs(Table, { children: [
					/* @__PURE__ */ jsx(TableCaption, {
						className: "sr-only",
						children: t("inventory::admin_stock.section.title")
					}),
					/* @__PURE__ */ jsx(TableHeader, {
						className: "bg-surface-sunken",
						children: /* @__PURE__ */ jsxs(TableRow, { children: [
							/* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.size") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.in_stock") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.held") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.available") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.threshold") }),
							card.wired ? /* @__PURE__ */ jsx(TableHead, { children: t("inventory::admin_stock.column.counts_on_stock") }) : null,
							/* @__PURE__ */ jsx(TableHead, {
								className: "w-12",
								children: /* @__PURE__ */ jsx("span", {
									className: "sr-only",
									children: t("inventory::admin_stock.actions")
								})
							})
						] })
					}),
					/* @__PURE__ */ jsx(TableBody, { children: card.sizes.map((size) => {
						const id = size.variantId;
						const name = nameOf(id);
						const countRow = {
							id,
							inStock: size.figures.inStock,
							held: size.figures.held,
							holdingOrders: size.figures.holdingOrders
						};
						return /* @__PURE__ */ jsxs(TableRow, {
							"data-test": `section-stock-${id}`,
							children: [
								/* @__PURE__ */ jsx(TableCell, { children: /* @__PURE__ */ jsxs("span", {
									className: "grid",
									children: [/* @__PURE__ */ jsx("span", { children: name }), /* @__PURE__ */ jsx("span", {
										className: "tw-figure text-label-12-mono text-ink-muted",
										dir: "ltr",
										children: variants.get(id)?.code ?? ""
									})]
								}) }),
								/* @__PURE__ */ jsx(TableCell, {
									className: "align-top",
									children: size.archived ? /* @__PURE__ */ jsxs("span", {
										className: "grid gap-1",
										children: [/* @__PURE__ */ jsx(InStock, {
											figures: size.figures,
											wired: card.wired
										}), /* @__PURE__ */ jsx("span", {
											className: "max-w-56 text-copy-13 text-ink-muted",
											"data-test": `archived-${store.code}-${id}`,
											children: archivedReason
										})]
									}) : countable ? /* @__PURE__ */ jsx(CountBox, {
										id: counts.idOf(id),
										label: t("inventory::admin_stock.box.label_store", {
											size: name,
											store: store.name
										}),
										value: counts.valueOf(countRow),
										onChange: (value) => counts.type(id, value),
										onEscape: () => counts.reset(id),
										onEnter: counts.save,
										changed: counts.changed(id),
										error: counts.error(id),
										warning: counts.warning(countRow)
									}) : /* @__PURE__ */ jsx(InStock, {
										figures: size.figures,
										wired: true
									})
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "tw-figure align-top",
									children: figure(locale, size.figures.held)
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "align-top",
									children: /* @__PURE__ */ jsxs("span", {
										className: "flex flex-wrap items-center gap-2",
										children: [/* @__PURE__ */ jsx("span", {
											className: "tw-figure",
											children: figure(locale, size.figures.available)
										}), /* @__PURE__ */ jsx(StockBadge, { figures: size.figures })]
									})
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "tw-figure align-top",
									children: threshold(t, locale, size.figures)
								}),
								card.wired ? /* @__PURE__ */ jsx(TableCell, {
									className: "align-top",
									children: /* @__PURE__ */ jsx(Switch, {
										checked: size.figures.stockDependent,
										disabled: size.archived,
										onCheckedChange: (on) => router.post("/admin/stock/counts-on-stock", {
											store: store.code,
											sizes: [id],
											on
										}, { preserveScroll: true }),
										"aria-label": t("inventory::admin_stock.counts_on_label", { size: `${name} (${store.name})` }),
										"data-test": `counts-on-${store.code}-${id}`
									})
								}) : null,
								/* @__PURE__ */ jsx(TableCell, {
									className: "text-end align-top",
									children: /* @__PURE__ */ jsx(SizeMenu, {
										name: `${name} (${store.name})`,
										store: store.code,
										size: id,
										adjustReason: card.wired ? wiredReason : size.archived ? archivedReason : null,
										thresholdReason: size.archived ? archivedReason : null,
										onAdjust: (trigger) => {
											opener.current = trigger;
											setDialog({
												kind: "adjust",
												size: {
													variantId: id,
													name,
													figures: size.figures
												}
											});
										},
										onThreshold: (trigger) => {
											opener.current = trigger;
											setDialog({
												kind: "threshold",
												size: id,
												name,
												current: size.figures.ownThreshold
											});
										}
									})
								})
							]
						}, id);
					}) })
				] })
			}),
			countable ? counts.bar() : null,
			/* @__PURE__ */ jsxs(Suspense, {
				fallback: null,
				children: [dialog?.kind === "adjust" ? /* @__PURE__ */ jsx(AdjustDialog, {
					store: store.code,
					size: dialog.size,
					onOpenChange: (open) => open ? void 0 : setDialog(null),
					returnFocusTo: opener
				}) : null, dialog?.kind === "threshold" ? /* @__PURE__ */ jsx(ThresholdDialog, {
					store: store.code,
					sizes: [dialog.size],
					name: dialog.name,
					current: dialog.current,
					defaultThreshold: card.defaultThreshold,
					onOpenChange: (open) => open ? void 0 : setDialog(null),
					returnFocusTo: opener
				}) : null]
			})
		]
	});
}
function SizeMenu({ name, store, size, adjustReason, thresholdReason, onAdjust, onThreshold }) {
	const t = useT();
	const more = useRef(null);
	return /* @__PURE__ */ jsxs(DropdownMenu, { children: [/* @__PURE__ */ jsx(DropdownMenuTrigger, {
		asChild: true,
		children: /* @__PURE__ */ jsx(MoreButton, {
			ref: more,
			name,
			"data-test": `section-stock-actions-${store}-${size}`
		})
	}), /* @__PURE__ */ jsxs(DropdownMenuContent, {
		align: "end",
		className: "min-w-56",
		children: [
			/* @__PURE__ */ jsx(Guarded, {
				label: t("inventory::admin_stock.menu.adjust"),
				reason: adjustReason,
				onSelect: () => onAdjust(more.current),
				test: "adjust-stock"
			}),
			/* @__PURE__ */ jsx(Guarded, {
				label: t("inventory::admin_stock.menu.threshold"),
				reason: thresholdReason,
				onSelect: () => onThreshold(more.current),
				test: "set-threshold"
			}),
			/* @__PURE__ */ jsx(Guarded, {
				label: t("inventory::admin_stock.menu.view"),
				onSelect: () => router.get("/admin/stock", {
					store,
					size
				}),
				test: "view-stock"
			})
		]
	})] });
}
export { ProductStock as default };
