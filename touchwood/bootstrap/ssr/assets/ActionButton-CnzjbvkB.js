import { t as cn } from "./cn-C2NdifnB.js";
import { n as useTranslator } from "./t-Da8CTZFi.js";
import { t as Button } from "./button-DoWvyMdE.js";
import { t as Spinner } from "./spinner-C8hxoQvt.js";
import { useState } from "react";
import { jsx, jsxs } from "react/jsx-runtime";
import { Tooltip } from "radix-ui";
// resources/js/components/ui/tooltip.tsx
function TooltipProvider({ delayDuration = 0, ...props }) {
	return /* @__PURE__ */ jsx(Tooltip.Provider, {
		"data-slot": "tooltip-provider",
		delayDuration,
		...props
	});
}
function Tooltip$1({ ...props }) {
	return /* @__PURE__ */ jsx(Tooltip.Root, {
		"data-slot": "tooltip",
		...props
	});
}
function TooltipTrigger({ ...props }) {
	return /* @__PURE__ */ jsx(Tooltip.Trigger, {
		"data-slot": "tooltip-trigger",
		...props
	});
}
function TooltipContent({ className, sideOffset = 0, children, ...props }) {
	return /* @__PURE__ */ jsx(Tooltip.Portal, { children: /* @__PURE__ */ jsxs(Tooltip.Content, {
		"data-slot": "tooltip-content",
		sideOffset,
		className: cn("z-50 w-fit origin-(--radix-tooltip-content-transform-origin) animate-in rounded-md bg-foreground px-3 py-1.5 text-xs text-balance text-background fade-in-0 zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95", className),
		...props,
		children: [children, /* @__PURE__ */ jsx(Tooltip.Arrow, { className: "z-50 size-2.5 translate-y-[calc(-50%_-_2px)] rotate-45 rounded-[2px] bg-foreground fill-foreground" })]
	}) });
}
// resources/js/components/ActionButton.tsx
function ActionButton({ loading = false, disabledReason, disabled = false, onClick, children, ...rest }) {
	const t = useTranslator();
	const [tip, setTip] = useState(false);
	const unavailable = disabled || disabledReason !== void 0;
	const inert = unavailable || loading;
	const button = /* @__PURE__ */ jsxs(Button, {
		...rest,
		"aria-disabled": inert || void 0,
		"aria-busy": loading || void 0,
		"data-loading": loading || void 0,
		className: unavailable ? `${rest.className ?? ""} cursor-not-allowed opacity-50` : rest.className,
		onClick: (event) => {
			if (inert) {
				event.preventDefault();
				return;
			}
			onClick?.(event);
		},
		children: [loading ? /* @__PURE__ */ jsx(Spinner, { "aria-label": t("ui.loading") }) : null, children]
	});
	return /* @__PURE__ */ jsxs(Tooltip$1, {
		open: disabledReason !== void 0 && tip,
		onOpenChange: setTip,
		children: [/* @__PURE__ */ jsx(TooltipTrigger, {
			asChild: true,
			children: button
		}), disabledReason === void 0 ? null : /* @__PURE__ */ jsx(TooltipContent, { children: disabledReason })]
	});
}
export { TooltipTrigger as a, TooltipProvider as i, Tooltip$1 as n, TooltipContent as r, ActionButton as t };
