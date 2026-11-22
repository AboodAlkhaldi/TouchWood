import { n as useTranslator } from "./t-Da8CTZFi.js";
import { n as NativeSelectOption } from "./native-select-C9cFEAFg.js";
import { n as SelectField } from "./Fields-BdHuDKNr.js";
import { router } from "@inertiajs/react";
import { jsx, jsxs } from "react/jsx-runtime";
// resources/js/components/StoreFilter.tsx
function StoreFilter({ stores, value, all = false, drop = [], className }) {
	const t = useTranslator();
	if (stores.length + (all ? 1 : 0) < 2) return null;
	const choose = (code) => {
		const params = new URLSearchParams(window.location.search);
		if (code === "") params.delete("store");
		else params.set("store", code);
		params.delete("page");
		for (const name of drop) params.delete(name);
		const query = params.toString();
		router.get(`${window.location.pathname}${query === "" ? "" : `?${query}`}`, {}, { preserveScroll: true });
	};
	return /* @__PURE__ */ jsxs(SelectField, {
		id: "store-filter",
		label: t("admin.store.label"),
		value: value ?? "",
		onChange: (event) => choose(event.target.value),
		className,
		"data-test": "store-filter",
		children: [all ? /* @__PURE__ */ jsx(NativeSelectOption, {
			value: "",
			children: t("admin.store.all")
		}) : null, stores.map((store) => /* @__PURE__ */ jsx(NativeSelectOption, {
			value: store.code,
			children: store.isActive ? store.name : t("admin.store.option_off", { store: store.name })
		}, store.code))]
	});
}
export { StoreFilter as t };
