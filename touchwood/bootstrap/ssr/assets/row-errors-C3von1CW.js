// resources/js/lib/row-errors.ts
var PREFIX = "rows.";
function rowErrors(errors) {
	const rows = {};
	for (const [key, message] of Object.entries(errors)) if (key.startsWith(PREFIX) && key.length > 5 && typeof message === "string" && message !== "") rows[key.slice(5)] = message;
	return rows;
}
/** The first of these rows, in the page's order, that the refusal marks - where the focus goes. */
function firstMarked(rows, id, marked) {
	return rows.find((row) => marked[id(row)] !== void 0);
}
export { rowErrors as n, firstMarked as t };
