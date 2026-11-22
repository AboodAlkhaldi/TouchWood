import { usePage } from "@inertiajs/react";
// resources/js/lib/t.ts
/**
* Replaces :name placeholders the way Laravel does, so one key reads the same on both sides.
*
* In one pass, the longest placeholder first, as Laravel's strtr() does: replaced one after another,
* ":to" ate the start of ":total" and the pager read "1–5 of 5tal" (B2B step 7, the first screen to
* use the pager).
*/
function fill(line, values) {
	const keys = Object.keys(values).sort((a, b) => b.length - a.length);
	if (keys.length === 0) return line;
	const pattern = new RegExp(keys.map((key) => `:${key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}`).join("|"), "g");
	return line.replace(pattern, (found) => String(values[found.slice(1)]));
}
/**
* The words behind a key, e.g. t('access::auth.sign_in').
*
* A key the page was not sent comes back as the key itself. That is deliberate: a missing word must
* be visible on the screen rather than rendering as an empty space nobody notices - and a test
* (tests/Architecture/TranslationKeysTest) fails the build for a key missing in either language, so
* this is the last resort, not the plan.
*/
function useTranslator() {
	const { translations } = usePage().props;
	return (key, values = {}) => fill(translations[key] ?? key, values);
}
/**
* The same lookup where there is no component to hold a hook - inside a table column definition,
* for example. It takes the translations it was given rather than reaching for the page.
*/
function translate(translations, key, values = {}) {
	return fill(translations[key] ?? key, values);
}
export { useTranslator as n, translate as t };
