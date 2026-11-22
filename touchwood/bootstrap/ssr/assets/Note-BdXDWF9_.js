import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { AlertTriangle, CheckCircle2, Info, XCircle } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
import { cva } from "class-variance-authority";
// resources/js/components/ui/alert.tsx
var alertVariants = cva("relative grid w-full grid-cols-[0_1fr] items-start gap-y-0.5 rounded-lg border px-4 py-3 text-sm has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current", {
	variants: { variant: {
		default: "bg-card text-card-foreground",
		destructive: "bg-card text-destructive *:data-[slot=alert-description]:text-destructive/90 [&>svg]:text-current"
	} },
	defaultVariants: { variant: "default" }
});
function Alert({ className, variant, ...props }) {
	return /* @__PURE__ */ jsx("div", {
		"data-slot": "alert",
		role: "alert",
		className: cn(alertVariants({ variant }), className),
		...props
	});
}
function AlertTitle({ className, ...props }) {
	return /* @__PURE__ */ jsx("div", {
		"data-slot": "alert-title",
		className: cn("col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight", className),
		...props
	});
}
function AlertDescription({ className, ...props }) {
	return /* @__PURE__ */ jsx("div", {
		"data-slot": "alert-description",
		className: cn("col-start-2 grid justify-items-start gap-1 text-sm text-muted-foreground [&_p]:leading-relaxed", className),
		...props
	});
}
// resources/js/components/Note.tsx
var LOOK = {
	default: {
		box: "bg-info-soft text-info shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-info)_25%,transparent)]",
		icon: Info
	},
	secondary: {
		box: "bg-surface-sunken text-ink-muted shadow-[0_0_0_1px_var(--tw-line)]",
		icon: Info
	},
	success: {
		box: "bg-good-soft text-good shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-good)_25%,transparent)]",
		icon: CheckCircle2
	},
	warning: {
		box: "bg-warn-soft text-warn shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-warn)_25%,transparent)]",
		icon: AlertTriangle
	},
	error: {
		box: "bg-bad-soft text-bad shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-bad)_25%,transparent)]",
		icon: XCircle
	}
};
function Note({ variant = "default", label, children, action, size = "medium", alert = false, className, ...rest }) {
	const look = LOOK[variant];
	const Icon = look.icon;
	return /* @__PURE__ */ jsxs(Alert, {
		...rest,
		role: alert ? "alert" : "note",
		className: cn("rounded-[var(--tw-radius)] border-0", size === "small" ? "px-3 py-2" : "px-4 py-3", look.box, className),
		children: [/* @__PURE__ */ jsx(Icon, { "aria-hidden": "true" }), /* @__PURE__ */ jsxs(AlertDescription, {
			className: cn("flex items-start gap-3 text-current", size === "small" ? "text-copy-13" : "text-copy-14"),
			children: [/* @__PURE__ */ jsxs("span", {
				className: "min-w-0 flex-1",
				children: [label === void 0 ? null : /* @__PURE__ */ jsxs("span", {
					className: "me-1 font-semibold",
					children: [label, ":"]
				}), children]
			}), action === void 0 ? null : /* @__PURE__ */ jsx("span", {
				className: "shrink-0",
				children: action
			})]
		})]
	});
}
export { AlertTitle as i, Alert as n, AlertDescription as r, Note as t };
