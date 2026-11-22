import { n as intlLocale, t as figure } from "./digits-D5IxcRsf.js";
// resources/js/pages/Pricing/Admin/checks.ts
/** A count's words in the page's plural form ("3 bands"): a key holding zero, one, two, few, many, other. */
function plural(t, locale, key, count, values = {}) {
	return t(`${key}.${new Intl.PluralRules(intlLocale(locale)).select(count)}`, {
		count: figure(locale, count),
		...values
	});
}
/**
* A left-to-right value - an amount with its letters, "−10%" - inside a translated sentence: kept in its own
* order whatever the sentence's direction (U+2066 … U+2069; lib/bidi.ts explains the isolates).
*/
function ltr(value) {
	return `\u2066${value}\u2069`;
}
var NUMBER = /^\d+(\.\d+)?$/;
/** A price written as a person types it, in the store's decimals: 14950, 2 → "149.50". */
function typedOf(minor, decimals) {
	if (decimals <= 0) return String(minor);
	const digits = String(Math.abs(minor)).padStart(decimals + 1, "0");
	return `${minor < 0 ? "-" : ""}${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}
/** A typed price in the currency's smallest unit, or null when it is not one in these decimals. */
function minorOf(text, decimals) {
	const value = text.trim();
	if (!NUMBER.test(value)) return null;
	const [whole = "0", fraction = ""] = value.split(".");
	if (fraction.length > decimals) return null;
	return Number(whole) * 10 ** decimals + Number(fraction.padEnd(decimals, "0") || "0");
}
/** The example a price's check names, in the store's decimals: "149.50", "149.500", "149". */
function exampleIn(decimals) {
	return decimals <= 0 ? "149" : `149.${"50".padEnd(decimals, "0").slice(0, decimals)}`;
}
/**
* What is wrong with a typed price or amount, in Pricing's words (§4.3.1 "Money and numbers"), or null.
*
* @param noun  "price" for a price box, "amount" for a discount's amount
*/
function priceProblem(text, decimals, t, locale, noun = "price") {
	const value = text.trim();
	const words = noun === "price" ? "pricing::admin.check.price" : "pricing::admin_discounts.check.amount";
	if (!NUMBER.test(value)) return t(`${words}_number`, { example: exampleIn(decimals) });
	if ((value.includes(".") ? value.length - value.indexOf(".") - 1 : 0) > decimals) return decimals === 0 ? t(`${words}_whole`) : plural(t, locale, `${words}_decimals`, decimals);
	return Number(value) > 0 ? null : t(`${words}_positive`);
}
/** What is wrong with a typed percentage: from 0.01 to 99.99, at most 2 decimals. */
function percentProblem(text, t) {
	const value = text.trim();
	if (!NUMBER.test(value)) return t("pricing::admin.check.percent_range");
	if (value.includes(".") && value.length - value.indexOf(".") - 1 > 2) return t("pricing::admin.check.percent_decimals");
	const number = Number(value);
	return number >= .01 && number <= 99.99 ? null : t("pricing::admin.check.percent_range");
}
var WHOLE = /^\d+$/;
/** What is wrong with each row as typed, by the rules of §1.4 and Q12 - the first problem of each. */
function bandProblems(rows, facts, decimals, t, locale) {
	return rows.map((row, index) => {
		const before = index > 0 ? rows[index - 1] : void 0;
		const problems = {};
		const from = row.from.trim();
		if (!WHOLE.test(from) || Number(from) < 1) problems.from = t("pricing::admin.bands_dialog.check.from_whole");
		else if (index === 0 && facts.minimum !== null && Number(from) !== facts.minimum) problems.from = t("pricing::admin.bands_dialog.check.first", { minimum: figure(locale, facts.minimum) });
		else if (before !== void 0 && WHOLE.test(before.from.trim()) && Number(from) <= Number(before.from.trim())) problems.from = t("pricing::admin.bands_dialog.check.from_order");
		else if (facts.maximum !== null && Number(from) > facts.maximum) problems.from = t("pricing::admin.bands_dialog.check.from_max", { maximum: figure(locale, facts.maximum) });
		const own = priceProblem(row.price, decimals, t, locale);
		const minor = minorOf(row.price, decimals);
		const previous = before === void 0 ? null : minorOf(before.price, decimals);
		if (own !== null) problems.price = own;
		else if (index === 0 && facts.retail !== null && minor !== null && minor >= facts.retail.minor) problems.price = t("pricing::admin.bands_dialog.check.first_price", { retail: ltr(facts.retail.text) });
		else if (previous !== null && minor !== null && minor >= previous) problems.price = t("pricing::admin.bands_dialog.check.price_order");
		return problems;
	});
}
export { percentProblem as a, typedOf as c, minorOf as i, exampleIn as n, plural as o, ltr as r, priceProblem as s, bandProblems as t };
