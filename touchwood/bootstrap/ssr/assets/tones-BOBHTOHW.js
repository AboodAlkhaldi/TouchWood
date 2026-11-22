// resources/js/lib/tones.ts
var TONE = {
	gray: "bg-ink-muted text-surface",
	blue: "bg-info text-ink-on-brand",
	green: "bg-good text-ink-on-brand",
	amber: "bg-warn text-ink-on-brand",
	red: "bg-bad text-ink-on-brand",
	"gray-subtle": "bg-surface-sunken text-ink-muted",
	"blue-subtle": "bg-info-soft text-info",
	"green-subtle": "bg-good-soft text-good",
	"amber-subtle": "bg-warn-soft text-warn",
	"red-subtle": "bg-bad-soft text-bad"
};
/** Geist's medium badge: 24 px high, label-12 type. */
var SIZE = "h-6 px-2.5 text-label-12";
/** The classes that give shadcn's Badge one of Geist's colours. */
function tone(name) {
	return `${TONE[name]} ${SIZE} border-transparent`;
}
export { tone as t };
