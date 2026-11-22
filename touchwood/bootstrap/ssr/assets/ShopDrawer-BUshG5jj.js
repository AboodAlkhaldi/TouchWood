import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { n as NativeSelectOption, t as NativeSelect } from "./native-select-C9cFEAFg.js";
import { t as figure } from "./digits-D5IxcRsf.js";
import { t as Badge } from "./badge-DLtaVvxf.js";
import { G as TabsContent, H as useLink, J as Collapsible, K as TabsList, W as Tabs, X as CollapsibleTrigger, Y as CollapsibleContent, at as ThemeToggle, q as TabsTrigger } from "../ssr.js";
import { Link, router, usePage } from "@inertiajs/react";
import { ChevronDown, LayoutDashboard, LogOut, UserRound } from "lucide-react";
import { Fragment, jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/shop/CategoryTree.tsx
function CategoryTree({ menu, words }) {
	return /* @__PURE__ */ jsx("ul", {
		className: "grid gap-1",
		children: menu.map((item) => /* @__PURE__ */ jsx(Branch, {
			item,
			words
		}, item.id))
	});
}
/** How many products a category's page lists, quiet beside its name; none from a menu kept before the counts were. */
function Count({ products }) {
	const locale = usePage().props.locale;
	if (products === 0) return /* @__PURE__ */ jsx("span", { className: "ms-auto" });
	return /* @__PURE__ */ jsx("span", {
		className: "tw-figure ms-auto ps-2 text-copy-13 text-ink-muted",
		"data-test": "category-count",
		children: figure(locale, products)
	});
}
function Branch({ item, words }) {
	const href = useLink()("storefront.catalog.category", { slug: item.slug });
	if (item.children.length === 0) return /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsxs(Link, {
		href,
		className: "flex min-h-11 items-center rounded-md px-2 text-label-14 text-ink hover:bg-muted",
		children: [/* @__PURE__ */ jsx("span", {
			className: "min-w-0 truncate",
			children: item.name
		}), /* @__PURE__ */ jsx(Count, { products: item.products })]
	}) });
	return /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsxs(Collapsible, { children: [/* @__PURE__ */ jsxs(CollapsibleTrigger, {
		className: "group flex min-h-11 w-full items-center rounded-md px-2 text-label-14 text-ink hover:bg-muted",
		children: [
			/* @__PURE__ */ jsx("span", {
				className: "min-w-0 truncate",
				children: item.name
			}),
			/* @__PURE__ */ jsx(Count, { products: item.products }),
			/* @__PURE__ */ jsx(ChevronDown, {
				"aria-hidden": "true",
				className: "ms-2 size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
			})
		]
	}), /* @__PURE__ */ jsx(CollapsibleContent, { children: /* @__PURE__ */ jsxs("ul", {
		className: "grid gap-1 ps-3",
		children: [/* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsx(Link, {
			href,
			className: "flex min-h-11 items-center rounded-md px-2 text-copy-14 text-ink-muted hover:bg-muted",
			children: (words.all_of ?? ":name").replace(":name", item.name)
		}) }), item.children.map((child) => /* @__PURE__ */ jsx(Branch, {
			item: child,
			words
		}, child.id))]
	}) })] }) });
}
// resources/js/components/content/ShopDrawer.tsx
/** What the drawer holds, under its head; the Sheet itself is the layout's (its parts are every page's already). */
function ShopDrawer({ alternate }) {
	const page = usePage();
	const { shop, shopper, staffView, locale, frame } = page.props;
	const t = useTranslator();
	const link = useLink();
	const words = frame?.content?.words ?? {};
	const catalog = frame?.catalog;
	if (shop === null || shop === void 0) return null;
	const other = locale === "ar" ? "en" : "ar";
	const rows = [
		{
			key: "orders",
			text: words.my_orders,
			href: link("storefront.sales.orders")
		},
		{
			key: "track",
			text: words.track,
			href: link("storefront.sales.orders")
		},
		{
			key: "company",
			text: words.company,
			href: link("storefront.company")
		}
	];
	return /* @__PURE__ */ jsxs(Fragment, { children: [/* @__PURE__ */ jsxs(Tabs, {
		defaultValue: "categories",
		className: "min-h-0 flex-1 gap-0",
		children: [
			/* @__PURE__ */ jsxs(TabsList, {
				className: "mx-4 mt-3 mb-1 grid h-10 grid-cols-2",
				"data-test": "drawer-switch",
				children: [/* @__PURE__ */ jsx(TabsTrigger, {
					value: "categories",
					"data-test": "drawer-categories",
					children: words.categories
				}), /* @__PURE__ */ jsx(TabsTrigger, {
					value: "account",
					"data-test": "drawer-account",
					children: words.account_settings
				})]
			}),
			/* @__PURE__ */ jsx(TabsContent, {
				value: "categories",
				className: "min-h-0 overflow-y-auto px-4 py-2",
				children: catalog ? /* @__PURE__ */ jsx(CategoryTree, {
					menu: catalog.menu,
					words: catalog.words
				}) : null
			}),
			/* @__PURE__ */ jsxs(TabsContent, {
				value: "account",
				className: "min-h-0 overflow-y-auto py-2",
				"data-test": "drawer-account-view",
				children: [
					staffView ? /* @__PURE__ */ jsxs("div", {
						className: "grid gap-1 border-b border-line px-4 pb-3",
						"data-test": "drawer-staff",
						children: [
							/* @__PURE__ */ jsx("span", {
								className: "truncate text-label-14 text-ink",
								children: staffView.name
							}),
							/* @__PURE__ */ jsxs("a", {
								href: "/admin",
								className: "flex min-h-11 items-center gap-3 text-copy-14 text-ink",
								"data-test": "drawer-staff-panel",
								children: [/* @__PURE__ */ jsx(LayoutDashboard, {
									"aria-hidden": "true",
									className: "size-5 text-brand"
								}), t("admin.panel")]
							}),
							/* @__PURE__ */ jsxs("button", {
								type: "button",
								className: "flex min-h-11 items-center gap-3 text-start text-copy-14 text-ink",
								"data-test": "drawer-staff-leave",
								onClick: () => router.post(link("storefront.staff-view.leave"), { store: shop.code }),
								children: [/* @__PURE__ */ jsx(LogOut, {
									"aria-hidden": "true",
									className: "size-5 text-brand"
								}), t("admin.staff_view.leave")]
							})
						]
					}) : /* @__PURE__ */ jsxs("div", {
						className: "flex items-center gap-3 bg-surface-sunken px-4 py-4",
						children: [/* @__PURE__ */ jsx("span", {
							className: "flex size-11 shrink-0 items-center justify-center rounded-full bg-surface",
							children: /* @__PURE__ */ jsx(UserRound, {
								"aria-hidden": "true",
								className: "size-5 text-brand"
							})
						}), /* @__PURE__ */ jsxs("span", {
							className: "grid min-w-0 gap-1",
							children: [/* @__PURE__ */ jsx("span", {
								className: "truncate text-label-14 text-ink",
								children: shopper ? (words.hello ?? ":name").replace(":name", shopper.name) : words.hello_guest
							}), shopper ? shopper.emailVerified ? null : /* @__PURE__ */ jsx(Badge, {
								asChild: true,
								variant: "outline",
								className: "border-transparent bg-warn-soft text-warn",
								children: /* @__PURE__ */ jsx(Link, {
									href: link("storefront.verify-email"),
									children: t("access::auth.verify_pending")
								})
							}) : /* @__PURE__ */ jsx(Button, {
								variant: "outline",
								size: "sm",
								asChild: true,
								className: "justify-self-start",
								children: /* @__PURE__ */ jsx(Link, {
									href: link("storefront.sign-in"),
									"data-test": "drawer-sign-in",
									children: t("access::auth.sign_in")
								})
							})]
						})]
					}),
					staffView ? null : /* @__PURE__ */ jsx("ul", {
						className: "grid py-1",
						children: rows.map((row) => /* @__PURE__ */ jsx("li", { children: /* @__PURE__ */ jsx(Link, {
							href: row.href,
							className: "flex min-h-12 items-center gap-3 px-4 text-copy-14 text-ink hover:bg-muted",
							"data-test": `drawer-${row.key}`,
							children: /* @__PURE__ */ jsx("span", {
								className: "flex-1",
								children: row.text
							})
						}) }, row.key))
					}),
					/* @__PURE__ */ jsxs("label", {
						className: "mx-4 mt-2 grid gap-2 border-t border-line pt-3 text-label-12 text-ink-muted",
						children: [/* @__PURE__ */ jsx("span", {
							className: "flex items-center gap-2",
							children: words.country
						}), /* @__PURE__ */ jsx(NativeSelect, {
							"data-test": "drawer-country",
							value: shop.code,
							"aria-label": t("platform::stores.choose_title"),
							onChange: (event) => router.visit(`/${event.target.value}/${locale}`),
							className: "w-full",
							children: shop.available.map((store) => /* @__PURE__ */ jsx(NativeSelectOption, {
								value: store.code,
								children: store.isActive ? store.name : `${store.name} (${t("admin.store.off")})`
							}, store.code))
						})]
					}),
					shopper && !staffView ? /* @__PURE__ */ jsxs("button", {
						type: "button",
						className: "mx-4 mt-3 flex min-h-12 w-[calc(100%-2rem)] items-center gap-3 border-t border-line text-start text-copy-14 text-bad",
						"data-test": "drawer-sign-out",
						onClick: () => router.post(link("storefront.account.sign-out")),
						children: [/* @__PURE__ */ jsx(LogOut, {
							"aria-hidden": "true",
							className: "size-5"
						}), t("access::auth.sign_out")]
					}) : null
				]
			})
		]
	}), /* @__PURE__ */ jsxs("div", {
		className: "grid grid-cols-2 gap-2 border-t border-line px-4 pt-3 pb-[calc(1rem+env(safe-area-inset-bottom))]",
		children: [shop.languages.includes(other) ? /* @__PURE__ */ jsx(Button, {
			asChild: true,
			variant: "secondary",
			className: "h-10",
			children: /* @__PURE__ */ jsx(Link, {
				href: alternate ?? page.url.replace(`/${shop.code}/${locale}`, `/${shop.code}/${other}`),
				lang: other,
				"data-test": `drawer-language-${other}`,
				children: other === "ar" ? "العربية" : "English"
			})
		}) : null, /* @__PURE__ */ jsx("span", {
			className: "flex items-center justify-center",
			"data-test": "drawer-theme",
			children: /* @__PURE__ */ jsx(ThemeToggle, {
				to: "/preferences",
				size: "default"
			})
		})]
	})] });
}
export { ShopDrawer as default };
