// resources/js/lib/digits.ts
var ARABIC_INDIC = 1632;
var EXTENDED_ARABIC_INDIC = 1776;
function toLatinDigits(value) {
	let out = "";
	for (const character of value) {
		const code = character.codePointAt(0) ?? 0;
		if (code >= ARABIC_INDIC && code <= 1641) {
			out += String(code - ARABIC_INDIC);
			continue;
		}
		if (code >= EXTENDED_ARABIC_INDIC && code <= 1785) {
			out += String(code - EXTENDED_ARABIC_INDIC);
			continue;
		}
		out += character;
	}
	return out;
}
function intlLocale(locale) {
	return locale === "ar" ? "ar-u-nu-latn" : "en";
}
/** A whole number in Latin digits, grouped as the page's language groups it (frontend.md §1.8). */
function figure(locale, value) {
	return new Intl.NumberFormat(intlLocale(locale)).format(value);
}
export { intlLocale as n, toLatinDigits as r, figure as t };
