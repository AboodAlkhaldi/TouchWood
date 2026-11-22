import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as Spinner } from "./spinner-C8hxoQvt.js";
import { t as ActionButton } from "./ActionButton-CnzjbvkB.js";
import { t as Label } from "./label-BuXzdX1o.js";
import { c as FieldSet, i as FieldError, o as FieldLabel, s as FieldLegend, t as Field } from "./field-Ce02Vx8L.js";
import { t as Note } from "./Note-BdXDWF9_.js";
import { a as InputGroupText, i as InputGroupInput, n as InputGroupAddon, t as InputGroup } from "./input-group-DCQYtebS.js";
import { r as toLatinDigits, t as figure } from "./digits-D5IxcRsf.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { r as formatMoment } from "./Time-xzOlytHD.js";
import { n as RadioGroupItem, t as RadioGroup } from "./radio-group-se2OA-ip.js";
import { a as TableHead, i as TableCell, n as TableBody, o as TableHeader, s as TableRow, t as Table$1 } from "./table-D4bMO-f5.js";
import { r as useLiveRead } from "./live-read-Dv7wC0si.js";
import { n as rowErrors } from "./row-errors-C3von1CW.js";
import { F as useInLanguage } from "../ssr.js";
import { router, usePage } from "@inertiajs/react";
import { Suspense, lazy, useEffect, useState } from "react";
import { X } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/campaign-prices.tsx
var TargetPicker = lazy(() => import("./target-picker-Bvgouv5l.js").then((module) => ({ default: module.TargetPicker })));
var KINDS = [
	"PRODUCT",
	"SIZE",
	"CATEGORY",
	"BRAND"
];
var LINES = [
	"RETAIL",
	"WHOLESALE",
	"BOTH"
];
function typedMinor(minor, decimals) {
	if (decimals <= 0) return String(minor);
	const digits = String(minor).padStart(decimals + 1, "0");
	return `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}
/** A percentage as typed, in basis points: 0.01 to 99.99, two places at most; null otherwise. */
function basisPoints(text) {
	const match = /^(\d{1,2})(?:\.(\d{1,2}))?$/.exec(text.trim());
	if (match === null) return null;
	const points = Number(match[1]) * 100 + Number((match[2] ?? "").padEnd(2, "0"));
	return points >= 1 && points <= 9999 ? points : null;
}
/** A price as typed, in the currency's minor units: above 0, at most its decimals; null otherwise. */
function minorUnits(text, decimals) {
	const match = /^(\d{1,9})(?:\.(\d+))?$/.exec(text.trim());
	if (match === null || (match[2] ?? "").length > decimals) return null;
	const minor = Number(match[1]) * 10 ** decimals + Number((match[2] ?? "").padEnd(decimals, "0") || "0");
	return minor > 0 ? minor : null;
}
function typedOf(target, decimals) {
	return {
		kind: target.kind,
		id: target.id,
		nameAr: target.nameAr,
		nameEn: target.nameEn,
		detail: target.detail,
		archived: target.archived,
		mode: target.amountMinor !== null ? "fixed" : "percent",
		value: target.amountMinor !== null ? typedMinor(target.amountMinor, decimals) : target.percentBp === null ? "" : String(target.percentBp / 100)
	};
}
function CampaignPrices({ run, version, onChanged }) {
	const t = useTranslator();
	const name = useInLanguage();
	const { errors, locale } = usePage().props;
	const marked = errors.rows_store === run.store.id ? rowErrors(errors) : {};
	const editable = run.mayPrice && run.pricesOpen;
	const [lines, setLines] = useState(run.prices?.lines ?? "BOTH");
	const [targets, setTargets] = useState(() => (run.prices?.targets ?? []).map((target) => typedOf(target, run.decimals)));
	const [leftOut, setLeftOut] = useState(() => (run.prices?.leftOut ?? []).map((product) => ({
		id: product.id,
		nameAr: product.nameAr,
		nameEn: product.nameEn,
		detail: null
	})));
	const [picking, setPicking] = useState(null);
	const [busy, setBusy] = useState(false);
	const [tried, setTried] = useState(false);
	const [changed, setChanged] = useState(false);
	useEffect(() => onChanged?.(changed), [changed, onChanged]);
	const problem = (target) => target.mode === "fixed" ? minorUnits(target.value, run.decimals) === null ? t("content::admin_campaigns.prices.amount_rule", { currency: run.currency }) : void 0 : basisPoints(target.value) === null ? t("content::admin_campaigns.prices.percent_rule") : void 0;
	const valid = targets.every((target) => problem(target) === void 0);
	const sent = {
		lines,
		targets: targets.map((target) => target.mode === "fixed" ? {
			kind: target.kind,
			id: target.id,
			amountMinor: minorUnits(target.value, run.decimals)
		} : {
			kind: target.kind,
			id: target.id,
			percentBp: basisPoints(target.value)
		}),
		leftOut: leftOut.map((product) => product.id)
	};
	const preview = useLiveRead("/admin/content/campaigns/preview", targets.length > 0 && valid ? {
		run: run.id,
		prices: JSON.stringify(sent)
	} : null);
	const ceiling = run.ceilingPercent;
	const deep = ceiling !== null && preview.data?.deepestBasisPoints != null && preview.data.deepestBasisPoints > ceiling * 100;
	const touch = (change) => {
		change();
		setChanged(true);
	};
	const add = (kind, picked) => touch(() => setTargets((was) => was.some((each) => each.kind === kind && each.id === picked.id) ? was : [...was, {
		kind,
		...picked,
		archived: false,
		mode: "percent",
		value: ""
	}]));
	const save = () => {
		setTried(true);
		if (!valid) return;
		router.post(`/admin/content/campaigns/runs/${run.id}/prices`, {
			version,
			prices: targets.length === 0 && leftOut.length === 0 ? null : sent
		}, {
			preserveScroll: true,
			preserveState: true,
			onStart: () => setBusy(true),
			onFinish: () => setBusy(false),
			onSuccess: () => setChanged(false)
		});
	};
	return /* @__PURE__ */ jsxs("div", {
		className: "grid min-w-0 gap-4",
		"data-test": `prices-${run.store.code}`,
		children: [
			!run.mayPrice ? /* @__PURE__ */ jsx(Note, {
				size: "small",
				"data-test": "prices-not-allowed",
				children: t("content::admin_campaigns.prices.not_allowed")
			}) : !run.pricesOpen ? /* @__PURE__ */ jsx(Note, {
				size: "small",
				"data-test": "prices-fixed",
				children: t("content::admin_campaigns.prices.fixed_running")
			}) : null,
			ceiling === null ? null : /* @__PURE__ */ jsx("p", {
				className: "tw-figure text-copy-13 text-ink-muted",
				"data-test": "ceiling",
				children: t("content::admin_campaigns.prices.ceiling", { percent: figure(locale, ceiling) })
			}),
			/* @__PURE__ */ jsxs(FieldSet, {
				className: "gap-2",
				children: [/* @__PURE__ */ jsx(FieldLegend, {
					variant: "label",
					className: "text-label-14 text-ink",
					children: t("content::admin_campaigns.prices.lines")
				}), /* @__PURE__ */ jsx(RadioGroup, {
					value: lines,
					onValueChange: (next) => touch(() => setLines(next)),
					disabled: !editable,
					className: "flex flex-wrap gap-4",
					"data-test": "lines",
					children: LINES.map((line) => /* @__PURE__ */ jsxs("div", {
						className: "flex items-center gap-2",
						children: [/* @__PURE__ */ jsx(RadioGroupItem, {
							id: `lines-${run.id}-${line}`,
							value: line,
							"data-test": `line-${line}`
						}), /* @__PURE__ */ jsx(Label, {
							htmlFor: `lines-${run.id}-${line}`,
							className: "font-normal",
							children: t(`content::admin_campaigns.prices.line.${line}`)
						})]
					}, line))
				})]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid min-w-0 gap-2",
				children: [
					/* @__PURE__ */ jsx("h3", {
						className: "text-label-14 text-ink",
						children: t("content::admin_campaigns.prices.targets")
					}),
					targets.length === 0 ? /* @__PURE__ */ jsx("p", {
						className: "text-copy-13 text-ink-muted",
						children: t("content::admin_campaigns.prices.none")
					}) : null,
					/* @__PURE__ */ jsx("ul", {
						className: "grid min-w-0 gap-3",
						"data-test": "targets",
						children: targets.map((target, index) => {
							const id = `target-${run.id}-${index}`;
							const shown = (tried || target.value !== "" ? problem(target) : void 0) ?? (target.kind === "SIZE" ? marked[target.id] : void 0);
							return /* @__PURE__ */ jsxs("li", {
								className: "material-small grid min-w-0 gap-2 p-3",
								"data-test": `target-${target.kind}-${target.id}`,
								"data-marked": marked[target.id] !== void 0 ? "true" : void 0,
								children: [/* @__PURE__ */ jsxs("div", {
									className: "flex min-w-0 items-start justify-between gap-2",
									children: [/* @__PURE__ */ jsxs("div", {
										className: "grid min-w-0 gap-0.5",
										children: [/* @__PURE__ */ jsx("span", {
											className: "truncate text-label-14 text-ink",
											children: name(target.nameAr, target.nameEn ?? target.nameAr)
										}), /* @__PURE__ */ jsxs("span", {
											className: "flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted",
											children: [
												t(`content::admin_campaigns.prices.kind.${target.kind}`),
												target.detail === null ? null : /* @__PURE__ */ jsx("bdi", {
													dir: "ltr",
													children: target.detail
												}),
												target.archived ? /* @__PURE__ */ jsx(Badge, {
													variant: "outline",
													children: t("content::admin_campaigns.prices.archived")
												}) : null
											]
										})]
									}), editable ? /* @__PURE__ */ jsx(Button, {
										type: "button",
										variant: "ghost",
										size: "icon",
										"aria-label": `${t("content::admin_campaigns.prices.remove")}: ${name(target.nameAr, target.nameEn ?? target.nameAr)}`,
										onClick: () => touch(() => setTargets((was) => was.filter((_, at) => at !== index))),
										"data-test": "remove-target",
										children: /* @__PURE__ */ jsx(X, { "aria-hidden": true })
									}) : null]
								}), /* @__PURE__ */ jsxs("div", {
									className: "flex flex-wrap items-end gap-3",
									children: [target.kind === "SIZE" ? /* @__PURE__ */ jsx(RadioGroup, {
										value: target.mode,
										onValueChange: (mode) => touch(() => setTargets((was) => was.map((each, at) => at === index ? {
											...each,
											mode,
											value: ""
										} : each))),
										disabled: !editable,
										className: "flex gap-3",
										children: ["percent", "fixed"].map((mode) => /* @__PURE__ */ jsxs("div", {
											className: "flex items-center gap-2",
											children: [/* @__PURE__ */ jsx(RadioGroupItem, {
												id: `${id}-${mode}`,
												value: mode
											}), /* @__PURE__ */ jsx(Label, {
												htmlFor: `${id}-${mode}`,
												className: "font-normal",
												children: t(`content::admin_campaigns.prices.${mode}`)
											})]
										}, mode))
									}) : null, /* @__PURE__ */ jsxs(Field, {
										className: "w-44",
										"data-invalid": shown ? true : void 0,
										children: [
											/* @__PURE__ */ jsx(FieldLabel, {
												htmlFor: id,
												className: target.kind === "SIZE" ? "sr-only" : void 0,
												children: t(`content::admin_campaigns.prices.${target.mode}`)
											}),
											/* @__PURE__ */ jsxs(InputGroup, { children: [/* @__PURE__ */ jsx(InputGroupInput, {
												id,
												dir: "ltr",
												inputMode: "decimal",
												className: "tw-figure",
												disabled: !editable,
												value: target.value,
												"aria-invalid": shown ? true : void 0,
												"aria-describedby": shown ? `${id}-error` : void 0,
												onChange: (event) => {
													const value = toLatinDigits(event.target.value);
													touch(() => setTargets((was) => was.map((each, at) => at === index ? {
														...each,
														value
													} : each)));
												},
												"data-test": "target-value"
											}), /* @__PURE__ */ jsx(InputGroupAddon, {
												align: "inline-end",
												children: /* @__PURE__ */ jsx(InputGroupText, { children: target.mode === "fixed" ? run.currency : "%" })
											})] }),
											shown ? /* @__PURE__ */ jsx(FieldError, {
												id: `${id}-error`,
												"data-test": "target-error",
												children: shown
											}) : null
										]
									})]
								})]
							}, `${target.kind}:${target.id}`);
						})
					}),
					editable ? /* @__PURE__ */ jsx("div", {
						className: "flex flex-wrap gap-2",
						children: KINDS.map((kind) => /* @__PURE__ */ jsx(Button, {
							type: "button",
							variant: "outline",
							size: "sm",
							onClick: () => setPicking(kind),
							"data-test": `add-${kind}`,
							children: t("content::admin_campaigns.prices.add", { kind: t(`content::admin_campaigns.prices.kind.${kind}`) })
						}, kind))
					}) : null
				]
			}),
			/* @__PURE__ */ jsxs("div", {
				className: "grid min-w-0 gap-2",
				children: [
					/* @__PURE__ */ jsx("h3", {
						className: "text-label-14 text-ink",
						children: t("content::admin_campaigns.prices.left_out")
					}),
					/* @__PURE__ */ jsx("p", {
						className: "text-copy-13 text-ink-muted",
						children: t("content::admin_campaigns.prices.left_out_helper")
					}),
					/* @__PURE__ */ jsx("ul", {
						className: "flex flex-wrap gap-2",
						"data-test": "left-out",
						children: leftOut.map((product) => /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsxs(Badge, {
							variant: "outline",
							className: "gap-1",
							children: [/* @__PURE__ */ jsx("span", {
								className: "max-w-48 truncate",
								children: name(product.nameAr, product.nameEn ?? product.nameAr)
							}), editable ? /* @__PURE__ */ jsx("button", {
								type: "button",
								"aria-label": `${t("content::admin_campaigns.prices.remove")}: ${name(product.nameAr, product.nameEn ?? product.nameAr)}`,
								onClick: () => touch(() => setLeftOut((was) => was.filter((each) => each.id !== product.id))),
								children: /* @__PURE__ */ jsx(X, {
									className: "size-3",
									"aria-hidden": true
								})
							}) : null]
						}) }, product.id))
					}),
					editable ? /* @__PURE__ */ jsx("div", { children: /* @__PURE__ */ jsx(Button, {
						type: "button",
						variant: "outline",
						size: "sm",
						onClick: () => setPicking("LEFT_OUT"),
						"data-test": "leave-out",
						children: t("content::admin_campaigns.prices.leave_out")
					}) }) : null
				]
			}),
			editable ? /* @__PURE__ */ jsxs("div", {
				className: "flex flex-wrap items-center gap-3",
				children: [/* @__PURE__ */ jsx(ActionButton, {
					type: "button",
					loading: busy,
					onClick: save,
					"data-test": "save-prices",
					children: t("content::admin_campaigns.prices.save")
				}), changed ? /* @__PURE__ */ jsx("span", {
					className: "text-copy-13 text-ink-muted",
					role: "status",
					"data-test": "prices-unsaved",
					children: t("content::admin_campaigns.prices.unsaved")
				}) : null]
			}) : null,
			/* @__PURE__ */ jsx(PricesPreview, {
				run,
				preview,
				deep,
				marked,
				empty: targets.length === 0
			}),
			run.skipped.length === 0 ? null : /* @__PURE__ */ jsx(Note, {
				variant: "warning",
				size: "small",
				"data-test": "skipped-now",
				children: /* @__PURE__ */ jsxs("span", {
					className: "grid gap-1",
					children: [/* @__PURE__ */ jsx("span", { children: t("content::admin_campaigns.prices.skipped_now") }), /* @__PURE__ */ jsx("span", {
						className: "flex flex-wrap gap-2",
						children: run.skipped.map((size) => /* @__PURE__ */ jsxs("span", {
							className: "text-copy-13",
							children: [
								name(size.nameAr, size.nameEn ?? size.nameAr),
								" ",
								/* @__PURE__ */ jsx("bdi", {
									dir: "ltr",
									children: size.detail
								})
							]
						}, size.id))
					})]
				})
			}),
			picking === null ? null : /* @__PURE__ */ jsx(Suspense, {
				fallback: null,
				children: /* @__PURE__ */ jsx(TargetPicker, {
					run: run.id,
					kind: picking === "LEFT_OUT" ? "PRODUCT" : picking,
					title: picking === "LEFT_OUT" ? t("content::admin_campaigns.prices.leave_out") : t("content::admin_campaigns.prices.add", { kind: t(`content::admin_campaigns.prices.kind.${picking}`) }),
					chosen: picking === "LEFT_OUT" ? leftOut.map((each) => each.id) : targets.filter((each) => each.kind === picking).map((each) => each.id),
					onPick: (picked) => picking === "LEFT_OUT" ? touch(() => setLeftOut((was) => was.some((each) => each.id === picked.id) ? was : [...was, picked])) : add(picking, picked),
					onClose: () => setPicking(null)
				})
			})
		]
	});
}
/**
* Pricing's preview of what is typed (§1.7 #6): the totals, the deepest reduction against the ceiling, each product's
* sizes with their new price or why they are skipped, the sizes a refusal names marked, and the other offers.
*/
function PricesPreview({ run, preview, deep, marked, empty }) {
	const t = useTranslator();
	const name = useInLanguage();
	const { locale } = usePage().props;
	const data = preview.data;
	const percent = (points) => figure(locale, points / 100);
	return /* @__PURE__ */ jsxs("section", {
		className: "grid min-w-0 gap-3",
		"aria-labelledby": `preview-${run.id}`,
		"aria-busy": preview.loading,
		"data-test": "preview",
		children: [
			/* @__PURE__ */ jsxs("div", {
				className: "flex items-center gap-2",
				children: [/* @__PURE__ */ jsx("h3", {
					id: `preview-${run.id}`,
					className: "text-label-14 text-ink",
					children: t("content::admin_campaigns.preview.title")
				}), preview.loading ? /* @__PURE__ */ jsx(Spinner, { "aria-label": t("content::admin_campaigns.preview.refreshing") }) : null]
			}),
			empty ? /* @__PURE__ */ jsx("p", {
				className: "text-copy-13 text-ink-muted",
				children: t("content::admin_campaigns.preview.empty")
			}) : null,
			preview.failed ? /* @__PURE__ */ jsx(Note, {
				variant: "error",
				size: "small",
				action: /* @__PURE__ */ jsx(Button, {
					type: "button",
					size: "sm",
					variant: "outline",
					onClick: preview.retry,
					children: t("content::admin_campaigns.preview.retry")
				}),
				children: t("content::admin_campaigns.preview.failed")
			}) : null,
			data === null ? null : /* @__PURE__ */ jsxs("div", {
				className: preview.loading ? "grid min-w-0 gap-3 opacity-60" : "grid min-w-0 gap-3",
				children: [
					/* @__PURE__ */ jsxs("p", {
						className: "tw-figure text-copy-13 text-ink",
						"data-test": "preview-totals",
						children: [
							t("content::admin_campaigns.preview.reached", {
								products: figure(locale, data.reachedProducts),
								sizes: figure(locale, data.reachedSizes)
							}),
							data.skippedSizes > 0 ? ` · ${t("content::admin_campaigns.preview.skipped", { count: figure(locale, data.skippedSizes) })}` : "",
							data.deepestBasisPoints === null ? "" : ` · ${t("content::admin_campaigns.preview.deepest", { percent: percent(data.deepestBasisPoints) })}`
						]
					}),
					deep && run.ceilingPercent !== null ? /* @__PURE__ */ jsx("p", {
						className: "text-copy-13 text-warn",
						"data-test": "preview-over-ceiling",
						children: t("content::admin_campaigns.prices.over_ceiling", { ceiling: figure(locale, run.ceilingPercent) })
					}) : null,
					data.products.map((product) => /* @__PURE__ */ jsxs("div", {
						className: "material-small min-w-0 overflow-x-auto",
						children: [/* @__PURE__ */ jsx("p", {
							className: "truncate px-3 pt-2 text-label-13 text-ink",
							children: name(product.nameAr, product.nameEn ?? product.nameAr)
						}), /* @__PURE__ */ jsxs(Table$1, { children: [/* @__PURE__ */ jsx(TableHeader, { children: /* @__PURE__ */ jsxs(TableRow, { children: [
							/* @__PURE__ */ jsx(TableHead, { children: t("content::admin_campaigns.preview.columns.size") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("content::admin_campaigns.preview.columns.retail") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("content::admin_campaigns.preview.columns.new") }),
							/* @__PURE__ */ jsx(TableHead, { children: t("content::admin_campaigns.preview.columns.off") })
						] }) }), /* @__PURE__ */ jsx(TableBody, { children: product.sizes.map((size) => /* @__PURE__ */ jsxs(TableRow, {
							"data-test": `preview-size-${size.variantId}`,
							"data-marked": marked[size.variantId] !== void 0 ? "true" : void 0,
							className: marked[size.variantId] !== void 0 ? "bg-bad-soft" : void 0,
							children: [
								/* @__PURE__ */ jsxs(TableCell, { children: [/* @__PURE__ */ jsx("bdi", {
									dir: "ltr",
									children: size.code
								}), marked[size.variantId] !== void 0 ? /* @__PURE__ */ jsx("span", {
									className: "block text-copy-13 text-bad",
									children: marked[size.variantId]
								}) : null] }),
								/* @__PURE__ */ jsx(TableCell, {
									className: "tw-figure whitespace-nowrap",
									children: size.retail === null ? "—" : /* @__PURE__ */ jsx("bdi", {
										dir: "ltr",
										children: `${size.retail} ${data.currency}`
									})
								}),
								/* @__PURE__ */ jsxs(TableCell, {
									className: "tw-figure whitespace-nowrap",
									children: [size.skipped !== null && size.skipped !== "past_ceiling" ? /* @__PURE__ */ jsx("span", {
										className: "text-ink-muted",
										children: t(`content::admin_campaigns.preview.skip.${size.skipped}`)
									}) : size.newPrice === null ? "—" : /* @__PURE__ */ jsx("bdi", {
										dir: "ltr",
										children: `${size.newPrice} ${data.currency}`
									}), size.skipped === "past_ceiling" ? /* @__PURE__ */ jsx("span", {
										className: "block text-copy-13 text-warn",
										children: t("content::admin_campaigns.preview.skip.past_ceiling")
									}) : null]
								}),
								/* @__PURE__ */ jsx(TableCell, {
									className: "tw-figure whitespace-nowrap",
									children: size.depthBp === null ? "—" : `${percent(size.depthBp)}%`
								})
							]
						}, size.variantId)) })] })]
					}, product.productId)),
					data.otherOffers.length === 0 ? null : /* @__PURE__ */ jsxs("div", {
						className: "grid gap-1",
						"data-test": "preview-offers",
						children: [/* @__PURE__ */ jsx("h4", {
							className: "text-label-13 text-ink",
							children: t("content::admin_campaigns.preview.offers")
						}), /* @__PURE__ */ jsx("ul", {
							className: "grid gap-1",
							children: data.otherOffers.map((offer) => /* @__PURE__ */ jsxs("li", {
								className: "flex flex-wrap items-center gap-2 text-copy-13 text-ink-muted",
								children: [
									/* @__PURE__ */ jsx("span", {
										className: "text-ink",
										children: t(`content::admin_campaigns.preview.offer.${offer.kind}`)
									}),
									/* @__PURE__ */ jsxs("span", {
										className: "tw-figure",
										children: [
											formatMoment(offer.startsAt, run.store.timezone, locale),
											" – ",
											offer.endsAt === null ? "—" : formatMoment(offer.endsAt, run.store.timezone, locale)
										]
									}),
									/* @__PURE__ */ jsx("span", {
										className: "tw-figure",
										children: t("content::admin_campaigns.preview.offer_sizes", { count: figure(locale, offer.sizes) })
									}),
									offer.alwaysWins ? /* @__PURE__ */ jsx(Badge, {
										variant: "outline",
										children: t("content::admin_campaigns.preview.always_wins")
									}) : null
								]
							}, `${offer.kind}:${offer.id}`))
						})]
					})
				]
			})
		]
	});
}
export { CampaignPrices };
