import { t as Button } from "./button-DoWvyMdE.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { a as TableHead, i as TableCell, n as TableBody, o as TableHeader, r as TableCaption, s as TableRow, t as Table } from "./table-D4bMO-f5.js";
import { t as Money } from "./Money-ygwrzJUU.js";
import { c as typedOf, o as plural, r as ltr } from "./checks-laWsOTXB.js";
import { _ as useLocale, a as PricingWords, c as StateBadge, d as nameIn, g as useDate, m as percentOf, n as Mark, v as useT } from "./parts-BU_rOjks.js";
import { d as OfferMenu, f as useOfferDialogs, h as offText, m as useBoxes, p as PriceBox } from "../ssr.js";
import { Link, usePage } from "@inertiajs/react";
import { Suspense, lazy, useRef, useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/sections/Pricing/ProductPrices.tsx
var BandsDialog = lazy(() => import("../ssr.js").then((n) => n.g).then((module) => ({ default: module.BandsDialog })));
function ProductPrices({ data, store }) {
	const card = data;
	return /* @__PURE__ */ jsx(PricingWords.Provider, {
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
	const date = useDate(card.timezone);
	const host = usePage().props;
	const [bands, setBands] = useState(null);
	const opener = useRef(null);
	const offerStore = {
		code: store.code,
		decimals: card.decimals,
		currency: card.currency,
		zone: card.timezone
	};
	const offers = useOfferDialogs(offerStore);
	const variants = new Map((host.variants ?? []).map((variant) => [variant.id, variant]));
	const nameOf = (id) => sizeLabel(locale, t("pricing::admin.one_size"), variants.get(id)?.values ?? []);
	const editable = card.mayEditPrices && !card.wired;
	const boxes = useBoxes(editable ? card.sizes.map((size) => ({
		id: size.variantId,
		saved: size.retail === null ? "" : typedOf(size.retail.price.minor, card.decimals)
	})) : [], {
		store: store.code,
		decimals: card.decimals,
		prefix: `price-${store.code}`
	});
	const productName = host.product === void 0 ? "" : nameIn(locale, host.product.nameAr, host.product.nameEn);
	const product = host.product === void 0 ? null : {
		productId: host.product.id,
		nameAr: host.product.nameAr,
		nameEn: host.product.nameEn,
		photo: null,
		codes: []
	};
	const asRow = (sale) => ({
		id: sale.id,
		productId: host.product?.id ?? "",
		productNameAr: host.product?.nameAr ?? "",
		productNameEn: host.product?.nameEn ?? null,
		state: sale.state,
		percentBasisPoints: sale.percentBasisPoints,
		salePrice: sale.salePrice,
		startsAt: sale.startsAt,
		endsAt: sale.endsAt,
		createdAt: null,
		alwaysWins: sale.alwaysWins,
		variantIds: sale.variantIds,
		sizeNames: sale.variantIds.map(nameOf),
		allSizes: false,
		sizesOn: sale.variantIds.length,
		productSizesOn: card.sizes.length,
		archivedSince: 0,
		unpricedSizeNames: sale.variantIds.filter((id) => card.sizes.find((size) => size.variantId === id)?.retail === null).map(nameOf)
	});
	const offLine = (sale) => {
		const off = ltr(sale.percentBasisPoints !== null ? `−${percentOf(locale, sale.percentBasisPoints)}%` : sale.salePrice?.text ?? "");
		if (sale.state === "SCHEDULED") return t("pricing::admin.offer.line_scheduled", {
			off,
			date: date(sale.startsAt)
		});
		return sale.endsAt === null ? t("pricing::admin.offer.line_open", { off }) : t("pricing::admin.offer.line", {
			off,
			date: date(sale.endsAt)
		});
	};
	return /* @__PURE__ */ jsxs("div", {
		className: "grid gap-4",
		"data-test": `pricing-section-${store.code}`,
		children: [
			/* @__PURE__ */ jsx("h3", {
				className: "text-heading-14 text-ink",
				children: t("pricing::admin.section.title")
			}),
			/* @__PURE__ */ jsx("div", {
				className: "overflow-x-auto",
				children: /* @__PURE__ */ jsxs(Table, { children: [
					/* @__PURE__ */ jsx(TableCaption, {
						className: "sr-only",
						children: t("pricing::admin.section.title")
					}),
					/* @__PURE__ */ jsx(TableHeader, {
						className: "bg-surface-sunken",
						children: /* @__PURE__ */ jsxs(TableRow, { children: [
							/* @__PURE__ */ jsx(TableHead, { children: t("pricing::admin.column.size") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("pricing::admin.column.retail_price") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("pricing::admin.column.today") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("pricing::admin.column.wholesale") })
						] })
					}),
					/* @__PURE__ */ jsx(TableBody, { children: card.sizes.map((size) => {
						const id = size.variantId;
						const name = nameOf(id);
						return /* @__PURE__ */ jsxs(TableRow, {
							"data-test": `section-size-${id}`,
							children: [
								/* @__PURE__ */ jsx(TableCell, { children: /* @__PURE__ */ jsxs("span", {
									className: "grid",
									children: [/* @__PURE__ */ jsx("span", { children: name }), /* @__PURE__ */ jsx("span", {
										className: "tw-figure text-label-12-mono text-ink-muted",
										dir: "ltr",
										children: variants.get(id)?.code ?? ""
									})]
								}) }),
								/* @__PURE__ */ jsxs(TableCell, {
									className: "align-top",
									children: [editable ? /* @__PURE__ */ jsx(PriceBox, {
										id: boxes.idOf(id),
										label: t("pricing::admin.box.label_store", {
											size: name,
											store: store.name
										}),
										currency: card.currency,
										value: boxes.valueOf({
											id,
											saved: size.retail === null ? "" : typedOf(size.retail.price.minor, card.decimals)
										}),
										onChange: (value) => boxes.type(id, value),
										onEscape: () => boxes.reset(id),
										onEnter: boxes.save,
										changed: boxes.changed(id),
										error: boxes.error(id)
									}) : size.retail !== null ? /* @__PURE__ */ jsx(Money, { value: size.retail.price }) : /* @__PURE__ */ jsx("span", {
										className: "text-copy-13 text-ink-muted",
										children: t("pricing::admin.no_price")
									}), size.retail !== null ? /* @__PURE__ */ jsx("span", {
										className: "mt-1 block text-label-12 text-ink-muted",
										children: t(`pricing::admin.source.${size.retail.source}`)
									}) : null]
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "align-top",
									children: size.today === null ? "—" : /* @__PURE__ */ jsxs("span", {
										className: "grid gap-1",
										children: [
											size.today.before !== null ? /* @__PURE__ */ jsx(Money, {
												value: size.today.before,
												className: "text-label-12 text-ink-muted line-through"
											}) : null,
											/* @__PURE__ */ jsx(Money, { value: size.today.price }),
											size.today.why === "SALE" || size.today.why === "CATEGORY" || size.today.why === "CAMPAIGN" ? /* @__PURE__ */ jsxs("span", {
												className: "flex flex-wrap gap-1",
												children: [/* @__PURE__ */ jsx(Mark, {
													tone: "green-subtle",
													children: size.today.why === "SALE" ? t("pricing::admin.badge.offer") : size.today.why === "CAMPAIGN" ? t("pricing::admin.badge.campaign") : t("pricing::admin.badge.discount")
												}), size.today.alwaysWins ? /* @__PURE__ */ jsx(Mark, {
													tone: "amber-subtle",
													children: t("pricing::admin.badge.always_wins")
												}) : null]
											}) : null
										]
									})
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "align-top",
									children: size.bands.length === 0 && !card.mayEditWholesale ? "—" : card.mayEditWholesale ? /* @__PURE__ */ jsx(Button, {
										type: "button",
										variant: "link",
										className: "h-auto p-0 tw-figure",
										onClick: (event) => {
											opener.current = event.currentTarget;
											setBands({
												variantId: id,
												name
											});
										},
										"data-test": `section-bands-${id}`,
										children: size.bands.length === 0 ? t("pricing::admin.menu.edit_wholesale") : plural(t, locale, "pricing::admin.bands", size.bands.length)
									}) : /* @__PURE__ */ jsx("span", {
										className: "tw-figure",
										children: plural(t, locale, "pricing::admin.bands", size.bands.length)
									})
								})
							]
						}, id);
					}) })
				] })
			}),
			boxes.bar(t("pricing::admin.box.save")),
			/* @__PURE__ */ jsxs("div", {
				className: "grid gap-2",
				children: [
					/* @__PURE__ */ jsx("h4", {
						className: "text-label-14 text-ink",
						children: t("pricing::admin.section.offers")
					}),
					card.sales.length === 0 ? /* @__PURE__ */ jsx("p", {
						className: "text-copy-13 text-ink-muted",
						children: t("pricing::admin.section.no_offers")
					}) : null,
					/* @__PURE__ */ jsx("ul", {
						className: "grid gap-1",
						children: card.sales.map((sale) => /* @__PURE__ */ jsxs("li", {
							className: "flex flex-wrap items-center gap-2 text-copy-14",
							"data-test": `section-offer-${sale.id}`,
							children: [
								/* @__PURE__ */ jsx(StateBadge, { state: sale.state }),
								/* @__PURE__ */ jsx("span", {
									className: "tw-figure",
									children: offLine(sale)
								}),
								sale.alwaysWins ? /* @__PURE__ */ jsx(Mark, {
									tone: "amber-subtle",
									children: t("pricing::admin.badge.always_wins")
								}) : null,
								/* @__PURE__ */ jsx(OfferMenu, {
									sale: asRow(sale),
									mayEdit: card.mayEditPrices,
									name: productName,
									onOpen: (mode, trigger) => offers.open({
										mode,
										sale: asRow(sale)
									}, trigger)
								})
							]
						}, sale.id))
					}),
					card.mayEditPrices ? /* @__PURE__ */ jsx(ActionButton, {
						type: "button",
						variant: "outline",
						size: "sm",
						className: "w-fit",
						onClick: (event) => offers.open({
							mode: "add",
							sale: null,
							product
						}, event.currentTarget),
						"data-test": `section-add-offer-${store.code}`,
						children: t("pricing::admin.menu.add_offer")
					}) : null
				]
			}),
			card.discounts.length > 0 ? /* @__PURE__ */ jsxs("div", {
				className: "grid gap-2",
				children: [/* @__PURE__ */ jsx("h4", {
					className: "text-label-14 text-ink",
					children: t("pricing::admin.section.discounts")
				}), /* @__PURE__ */ jsx("ul", {
					className: "grid gap-1",
					children: card.discounts.map((discount) => {
						const line = t(discount.endsAt === null ? "pricing::admin.discount_line" : "pricing::admin.discount_until", {
							category: discount.categoryPath.join(" › "),
							off: discount.percentBasisPoints !== null ? t("pricing::admin.percent_off", { percent: ltr(percentOf(locale, discount.percentBasisPoints)) }) : offText(t, locale, discount),
							date: discount.endsAt === null ? "" : date(discount.endsAt)
						});
						return /* @__PURE__ */ jsxs("li", {
							className: "flex flex-wrap items-center gap-2 text-copy-14",
							"data-test": `section-discount-${discount.id}`,
							children: [/* @__PURE__ */ jsx(StateBadge, { state: discount.state }), /* @__PURE__ */ jsx(Link, {
								href: `/admin/category-discounts/${discount.id}`,
								className: "underline underline-offset-4",
								children: line
							})]
						}, discount.id);
					})
				})]
			}) : null,
			(card.campaigns ?? []).length > 0 ? /* @__PURE__ */ jsxs("div", {
				className: "grid gap-2",
				children: [/* @__PURE__ */ jsx("h4", {
					className: "text-label-14 text-ink",
					children: t("pricing::admin.section.campaigns")
				}), /* @__PURE__ */ jsx("ul", {
					className: "grid gap-1",
					children: (card.campaigns ?? []).map((campaign) => /* @__PURE__ */ jsxs("li", {
						className: "flex flex-wrap items-center gap-2 text-copy-14",
						"data-test": `section-campaign-${campaign.id}`,
						children: [
							/* @__PURE__ */ jsx(StateBadge, { state: campaign.state }),
							/* @__PURE__ */ jsx("span", { children: t("pricing::admin.badge.campaign") }),
							/* @__PURE__ */ jsx("span", {
								className: "tw-figure text-copy-13 text-ink-muted",
								children: t("pricing::admin.note.dates", {
									start: date(campaign.startsAt),
									end: campaign.endsAt === null ? t("pricing::admin.no_end") : date(campaign.endsAt)
								})
							})
						]
					}, campaign.id))
				})]
			}) : null,
			offers.dialogs,
			bands !== null ? /* @__PURE__ */ jsx(Suspense, {
				fallback: null,
				children: /* @__PURE__ */ jsx(BandsDialog, {
					store: {
						code: store.code,
						decimals: card.decimals,
						currency: card.currency
					},
					size: bands,
					open: true,
					onOpenChange: (open) => open ? void 0 : setBands(null),
					returnFocusTo: opener
				})
			}) : null
		]
	});
}
export { ProductPrices as default };
