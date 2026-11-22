import { t as cn } from "./cn-C2NdifnB.js";
import "./dialog-C7NRbZ1B.js";
import "react";
import { SearchIcon } from "lucide-react";
import { jsx, jsxs } from "react/jsx-runtime";
import { Command } from "cmdk";
// resources/js/components/ui/command.tsx
function Command$1({ className, ...props }) {
	return /* @__PURE__ */ jsx(Command, {
		"data-slot": "command",
		className: cn("flex h-full w-full flex-col overflow-hidden rounded-md bg-popover text-popover-foreground", className),
		...props
	});
}
function CommandInput({ className, ...props }) {
	return /* @__PURE__ */ jsxs("div", {
		"data-slot": "command-input-wrapper",
		className: "flex h-9 items-center gap-2 border-b px-3",
		children: [/* @__PURE__ */ jsx(SearchIcon, { className: "size-4 shrink-0 opacity-50" }), /* @__PURE__ */ jsx(Command.Input, {
			"data-slot": "command-input",
			className: cn("flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50", className),
			...props
		})]
	});
}
function CommandList({ className, ...props }) {
	return /* @__PURE__ */ jsx(Command.List, {
		"data-slot": "command-list",
		className: cn("max-h-[300px] scroll-py-1 overflow-x-hidden overflow-y-auto", className),
		...props
	});
}
function CommandEmpty({ ...props }) {
	return /* @__PURE__ */ jsx(Command.Empty, {
		"data-slot": "command-empty",
		className: "py-6 text-center text-sm",
		...props
	});
}
function CommandGroup({ className, ...props }) {
	return /* @__PURE__ */ jsx(Command.Group, {
		"data-slot": "command-group",
		className: cn("overflow-hidden p-1 text-foreground [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground", className),
		...props
	});
}
function CommandItem({ className, ...props }) {
	return /* @__PURE__ */ jsx(Command.Item, {
		"data-slot": "command-item",
		className: cn("relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 data-[selected=true]:bg-muted data-[selected=true]:text-accent-foreground [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 [&_svg:not([class*='text-'])]:text-muted-foreground", className),
		...props
	});
}
export { CommandItem as a, CommandInput as i, CommandEmpty as n, CommandList as o, CommandGroup as r, Command$1 as t };
