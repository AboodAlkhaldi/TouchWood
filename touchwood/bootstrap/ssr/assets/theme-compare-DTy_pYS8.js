import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { G as TabsContent, K as TabsList, P as isFilled, W as Tabs, q as TabsTrigger } from "../ssr.js";
import { usePage } from "@inertiajs/react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/content/theme-compare.tsx
function art(part, slot, locale) {
	return (part.images[`${slot}:${locale}`] ?? part.images[`${slot}:any`] ?? null)?.thumbnail ?? null;
}
function ground(url) {
	return url === null ? void 0 : {
		backgroundImage: `url("${url}")`,
		backgroundSize: "cover",
		backgroundPosition: "center"
	};
}
function Frame({ title, children, test }) {
	return /* @__PURE__ */ jsxs("figure", {
		className: "grid min-w-0 gap-1",
		"data-test": test,
		children: [/* @__PURE__ */ jsx("figcaption", {
			className: "text-label-12 text-ink-muted",
			children: title
		}), /* @__PURE__ */ jsx("div", {
			className: "material-small min-w-0 overflow-hidden bg-surface",
			children
		})]
	});
}
/** One width's drawing of the part, with or without the theme. */
function Drawing({ spec, part, width, themed }) {
	const t = useTranslator();
	const { locale } = usePage().props;
	const words = locale === "ar" ? part.textAr : part.textEn || part.textAr;
	const slot = width === "phone" ? "PHONE" : "DESKTOP";
	const line = "h-2 rounded-full bg-surface-sunken";
	switch (spec.kind) {
		case "ANNOUNCEMENT": return /* @__PURE__ */ jsx("div", {
			className: "bg-surface-sunken",
			style: themed ? ground(art(part, slot, locale)) : void 0,
			children: /* @__PURE__ */ jsx("p", {
				className: "truncate px-3 py-1 text-center text-copy-13 text-ink",
				children: themed && words !== "" ? words : "—"
			})
		});
		case "HERO":
		case "LISTING": {
			const url = themed ? art(part, slot, locale) : null;
			return /* @__PURE__ */ jsxs("div", {
				className: "grid gap-2 p-2",
				children: [/* @__PURE__ */ jsx("div", {
					className: spec.kind === "HERO" ? width === "phone" ? "aspect-[2/1] rounded-md bg-surface-sunken" : "aspect-[16/7] rounded-md bg-surface-sunken" : "aspect-[16/3] rounded-md bg-surface-sunken",
					style: ground(url)
				}), /* @__PURE__ */ jsx("div", { className: line })]
			});
		}
		case "PROMISES":
		case "POINTS":
		case "LOGOS":
		case "FOOTER": {
			const url = themed ? art(part, "BAND", "any") : null;
			const promises = part.promises.map((each) => locale === "ar" ? each.ar : each.en || each.ar).filter((each) => each !== "");
			return /* @__PURE__ */ jsxs("div", {
				className: "grid min-h-16 content-center gap-2 bg-surface-sunken p-3",
				style: ground(url),
				children: [themed && words !== "" ? /* @__PURE__ */ jsx("p", {
					className: "truncate text-label-13 text-ink",
					children: words
				}) : /* @__PURE__ */ jsx("div", { className: "h-2 w-1/3 rounded-full bg-surface" }), spec.kind === "PROMISES" ? /* @__PURE__ */ jsx("div", {
					className: width === "phone" ? "grid grid-cols-2 gap-1" : "grid grid-cols-4 gap-1",
					children: (themed && promises.length > 0 ? promises : [
						"",
						"",
						"",
						""
					]).map((each, index) => /* @__PURE__ */ jsx("span", {
						className: "truncate rounded-sm bg-surface px-1 text-copy-13 text-ink",
						children: each || "—"
					}, index))
				}) : null]
			});
		}
		case "FLAG":
		case "BADGE": return /* @__PURE__ */ jsx("div", {
			className: width === "phone" ? "grid grid-cols-2 gap-2 p-2" : "grid grid-cols-4 gap-2 p-2",
			children: [0, 1].map((index) => /* @__PURE__ */ jsxs("div", {
				className: "relative grid gap-1 rounded-md bg-surface-sunken p-1",
				children: [
					themed && spec.kind === "FLAG" && art(part, "RIBBON", "any") !== null ? /* @__PURE__ */ jsx("div", {
						className: "h-3 rounded-sm",
						style: ground(art(part, "RIBBON", "any"))
					}) : null,
					/* @__PURE__ */ jsx("div", { className: "aspect-square rounded-sm bg-surface" }),
					themed && spec.kind === "FLAG" && art(part, "FLAG", "any") !== null ? /* @__PURE__ */ jsx("div", {
						className: "absolute end-1 top-1 size-5",
						style: ground(art(part, "FLAG", "any"))
					}) : null,
					themed && spec.kind === "BADGE" && words !== "" ? /* @__PURE__ */ jsx(Badge, {
						variant: "secondary",
						className: "max-w-full truncate",
						children: words
					}) : null,
					/* @__PURE__ */ jsx("div", { className: line })
				]
			}, index))
		});
		case "CART": return /* @__PURE__ */ jsxs("div", {
			className: "grid gap-2 p-2",
			children: [
				themed && words !== "" ? /* @__PURE__ */ jsx("p", {
					className: "truncate rounded-sm bg-surface-sunken px-2 py-1 text-copy-13 text-ink",
					children: words
				}) : null,
				/* @__PURE__ */ jsx("div", { className: line }),
				/* @__PURE__ */ jsx("div", { className: line })
			]
		});
		case "BACKDROP": return width === "phone" ? /* @__PURE__ */ jsxs("div", {
			className: "grid gap-2 p-2",
			children: [/* @__PURE__ */ jsx("div", { className: line }), /* @__PURE__ */ jsx("div", { className: line })]
		}) : /* @__PURE__ */ jsxs("div", {
			className: "flex min-h-24",
			children: [
				/* @__PURE__ */ jsx("div", {
					className: "w-1/6",
					style: themed ? ground(art(part, "START", "any")) : void 0
				}),
				/* @__PURE__ */ jsxs("div", {
					className: "grid flex-1 content-start gap-2 bg-surface p-2",
					children: [/* @__PURE__ */ jsx("div", { className: line }), /* @__PURE__ */ jsx("div", { className: line })]
				}),
				/* @__PURE__ */ jsx("div", {
					className: "w-1/6",
					style: themed ? ground(art(part, "END", "any")) : void 0
				})
			]
		});
		default: return /* @__PURE__ */ jsx("p", {
			className: "p-2 text-copy-13 text-ink-muted",
			children: t("content::admin_themes.compare.empty")
		});
	}
}
function ThemeCompare({ spec, part }) {
	const t = useTranslator();
	return /* @__PURE__ */ jsxs("section", {
		className: "grid min-w-0 content-start gap-3",
		"aria-labelledby": "theme-compare",
		"data-test": "theme-compare",
		children: [
			/* @__PURE__ */ jsx("h2", {
				id: "theme-compare",
				className: "text-heading-16 text-ink",
				children: t("content::admin_themes.compare.title")
			}),
			/* @__PURE__ */ jsx("p", {
				className: "text-copy-13 text-ink-muted",
				children: t("content::admin_themes.compare.body")
			}),
			/* @__PURE__ */ jsx("p", {
				className: "text-label-13 text-ink",
				"data-test": "compare-part",
				children: t(`content::admin_themes.parts.name.${spec.kind}`)
			}),
			isFilled(part) ? null : /* @__PURE__ */ jsx("p", {
				className: "text-copy-13 text-ink-muted",
				children: t("content::admin_themes.compare.empty")
			}),
			/* @__PURE__ */ jsxs(Tabs, {
				defaultValue: "phone",
				children: [/* @__PURE__ */ jsxs(TabsList, {
					variant: "line",
					children: [/* @__PURE__ */ jsx(TabsTrigger, {
						value: "phone",
						"data-test": "compare-phone",
						children: t("content::admin_themes.compare.phone")
					}), /* @__PURE__ */ jsx(TabsTrigger, {
						value: "desktop",
						"data-test": "compare-desktop",
						children: t("content::admin_themes.compare.desktop")
					})]
				}), ["phone", "desktop"].map((width) => /* @__PURE__ */ jsx(TabsContent, {
					value: width,
					className: "pt-3",
					children: /* @__PURE__ */ jsxs("div", {
						className: width === "phone" ? "mx-auto grid w-48 gap-3" : "grid gap-3",
						"aria-hidden": true,
						children: [/* @__PURE__ */ jsx(Frame, {
							title: t("content::admin_themes.compare.default"),
							test: `compare-${width}-default`,
							children: /* @__PURE__ */ jsx(Drawing, {
								spec,
								part,
								width,
								themed: false
							})
						}), /* @__PURE__ */ jsx(Frame, {
							title: t("content::admin_themes.compare.theme"),
							test: `compare-${width}-theme`,
							children: /* @__PURE__ */ jsx(Drawing, {
								spec,
								part,
								width,
								themed: true
							})
						})]
					})
				}, width))]
			})
		]
	});
}
export { ThemeCompare };
