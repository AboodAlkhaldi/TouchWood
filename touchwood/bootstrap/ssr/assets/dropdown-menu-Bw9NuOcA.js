import { t as cn } from "./cn-C2NdifnB.js";
import "react";
import { CheckIcon, CircleIcon } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
import { DropdownMenu } from "radix-ui";
// resources/js/components/ui/dropdown-menu.tsx
function DropdownMenu$1({ ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Root, {
		"data-slot": "dropdown-menu",
		...props
	});
}
function DropdownMenuTrigger({ ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Trigger, {
		"data-slot": "dropdown-menu-trigger",
		...props
	});
}
function DropdownMenuContent({ className, sideOffset = 4, ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Portal, { children: /* @__PURE__ */ jsx(DropdownMenu.Content, {
		"data-slot": "dropdown-menu-content",
		sideOffset,
		className: cn("z-50 max-h-(--radix-dropdown-menu-content-available-height) min-w-[8rem] origin-(--radix-dropdown-menu-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95", className),
		...props
	}) });
}
function DropdownMenuGroup({ ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Group, {
		"data-slot": "dropdown-menu-group",
		...props
	});
}
function DropdownMenuItem({ className, inset, variant = "default", ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Item, {
		"data-slot": "dropdown-menu-item",
		"data-inset": inset,
		"data-variant": variant,
		className: cn("relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none focus:bg-muted focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:ps-8 data-[variant=destructive]:text-destructive data-[variant=destructive]:focus:bg-destructive/10 data-[variant=destructive]:focus:text-destructive dark:data-[variant=destructive]:focus:bg-destructive/20 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 [&_svg:not([class*='text-'])]:text-muted-foreground data-[variant=destructive]:*:[svg]:text-destructive!", className),
		...props
	});
}
function DropdownMenuCheckboxItem({ className, children, checked, ...props }) {
	return /* @__PURE__ */ jsxs(DropdownMenu.CheckboxItem, {
		"data-slot": "dropdown-menu-checkbox-item",
		className: cn("relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pe-2 ps-8 text-sm outline-hidden select-none focus:bg-muted focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4", className),
		checked,
		...props,
		children: [/* @__PURE__ */ jsx("span", {
			className: "pointer-events-none absolute start-2 flex size-3.5 items-center justify-center",
			children: /* @__PURE__ */ jsx(DropdownMenu.ItemIndicator, { children: /* @__PURE__ */ jsx(CheckIcon, { className: "size-4" }) })
		}), children]
	});
}
function DropdownMenuRadioGroup({ ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.RadioGroup, {
		"data-slot": "dropdown-menu-radio-group",
		...props
	});
}
function DropdownMenuRadioItem({ className, children, ...props }) {
	return /* @__PURE__ */ jsxs(DropdownMenu.RadioItem, {
		"data-slot": "dropdown-menu-radio-item",
		className: cn("relative flex cursor-default items-center gap-2 rounded-sm py-1.5 pe-2 ps-8 text-sm outline-hidden select-none focus:bg-muted focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4", className),
		...props,
		children: [/* @__PURE__ */ jsx("span", {
			className: "pointer-events-none absolute start-2 flex size-3.5 items-center justify-center",
			children: /* @__PURE__ */ jsx(DropdownMenu.ItemIndicator, { children: /* @__PURE__ */ jsx(CircleIcon, { className: "size-2 fill-current" }) })
		}), children]
	});
}
function DropdownMenuLabel({ className, inset, ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Label, {
		"data-slot": "dropdown-menu-label",
		"data-inset": inset,
		className: cn("px-2 py-1.5 text-sm font-medium data-[inset]:ps-8", className),
		...props
	});
}
function DropdownMenuSeparator({ className, ...props }) {
	return /* @__PURE__ */ jsx(DropdownMenu.Separator, {
		"data-slot": "dropdown-menu-separator",
		className: cn("-mx-1 my-1 h-px bg-border", className),
		...props
	});
}
export { DropdownMenuItem as a, DropdownMenuRadioItem as c, DropdownMenuGroup as i, DropdownMenuSeparator as l, DropdownMenuCheckboxItem as n, DropdownMenuLabel as o, DropdownMenuContent as r, DropdownMenuRadioGroup as s, DropdownMenu$1 as t, DropdownMenuTrigger as u };
