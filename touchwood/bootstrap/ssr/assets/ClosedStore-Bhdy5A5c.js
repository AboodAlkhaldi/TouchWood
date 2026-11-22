import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { r as CardContent, t as Card } from "./card-fzqydjD8.js";
import { U as isolate, n as P, z as StorefrontLayout } from "../ssr.js";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/sales/payment/ClosedStore.tsx
/** The store switched off while paying: one page, once, with a way to the main store (payments.md §1.10). */
function ClosedStore({ view }) {
	const t = useTranslator();
	const words = view.orderNumber !== null ? t(`${P}.closed.paid`, { number: isolate(view.orderNumber) }) : view.nothingCharged || view.declineCode !== null ? t(`${P}.closed.nothing_taken`) : t(`${P}.closed.not_completed`);
	return /* @__PURE__ */ jsx(StorefrontLayout, {
		title: t(`${P}.closed.title`),
		children: /* @__PURE__ */ jsx(Card, {
			className: "material-base mx-auto w-full max-w-xl gap-3 border-0 py-6",
			"data-test": "payment-store-closed",
			children: /* @__PURE__ */ jsxs(CardContent, {
				className: "grid justify-items-center gap-3 px-6 text-center",
				children: [
					/* @__PURE__ */ jsx("h1", {
						className: "text-heading-24 text-ink",
						children: t(`${P}.closed.title`)
					}),
					/* @__PURE__ */ jsx("p", {
						className: "text-copy-14 text-ink",
						children: words
					}),
					view.mainStoreUrl === null ? null : /* @__PURE__ */ jsx(Button, {
						asChild: true,
						children: /* @__PURE__ */ jsx("a", {
							href: view.mainStoreUrl,
							"data-test": "payment-main-store",
							children: t(`${P}.closed.main`)
						})
					})
				]
			})
		})
	});
}
export { ClosedStore as default };
