import { t as cn } from "./cn-C2NdifnB.js";
import { t as figure } from "./digits-D5IxcRsf.js";
import { R as colour } from "../ssr.js";
import * as React from "react";
import { Fragment as Fragment$1, jsx, jsxs } from "react/jsx-runtime";
import * as RechartsPrimitive from "recharts";
import { Bar, BarChart, CartesianGrid, Cell, Label, Line, LineChart, Pie, PieChart, XAxis, YAxis } from "recharts";
// resources/js/components/ui/chart.tsx
var THEMES = {
	light: "",
	dark: ".dark"
};
var INITIAL_DIMENSION = {
	width: 320,
	height: 200
};
var ChartContext = React.createContext(null);
function useChart() {
	const context = React.useContext(ChartContext);
	if (!context) throw new Error("useChart must be used within a <ChartContainer />");
	return context;
}
function ChartContainer({ id, className, children, config, initialDimension = INITIAL_DIMENSION, ...props }) {
	const uniqueId = React.useId();
	const chartId = `chart-${id ?? uniqueId.replace(/:/g, "")}`;
	return /* @__PURE__ */ jsx(ChartContext.Provider, {
		value: { config },
		children: /* @__PURE__ */ jsxs("div", {
			"data-slot": "chart",
			"data-chart": chartId,
			className: cn("flex aspect-video justify-center text-xs [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line[stroke='#ccc']]:stroke-border/50 [&_.recharts-curve.recharts-tooltip-cursor]:stroke-border [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-layer]:outline-hidden [&_.recharts-polar-grid_[stroke='#ccc']]:stroke-border [&_.recharts-radial-bar-background-sector]:fill-muted [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-reference-line_[stroke='#ccc']]:stroke-border [&_.recharts-sector]:outline-hidden [&_.recharts-sector[stroke='#fff']]:stroke-transparent [&_.recharts-surface]:outline-hidden", className),
			...props,
			children: [/* @__PURE__ */ jsx(ChartStyle, {
				id: chartId,
				config
			}), /* @__PURE__ */ jsx(RechartsPrimitive.ResponsiveContainer, {
				initialDimension,
				children
			})]
		})
	});
}
var ChartStyle = ({ id, config }) => {
	const colorConfig = Object.entries(config).filter(([, config]) => config.theme ?? config.color);
	if (!colorConfig.length) return null;
	return /* @__PURE__ */ jsx("style", { dangerouslySetInnerHTML: { __html: Object.entries(THEMES).map(([theme, prefix]) => `
${prefix} [data-chart=${id}] {
${colorConfig.map(([key, itemConfig]) => {
		const color = itemConfig.theme?.[theme] ?? itemConfig.color;
		return color ? `  --color-${key}: ${color};` : null;
	}).join("\n")}
}
`).join("\n") } });
};
var ChartTooltip = RechartsPrimitive.Tooltip;
function ChartTooltipContent({ active, payload, className, indicator = "dot", hideLabel = false, hideIndicator = false, label, labelFormatter, labelClassName, formatter, color, nameKey, labelKey }) {
	const { config } = useChart();
	const tooltipLabel = React.useMemo(() => {
		if (hideLabel || !payload?.length) return null;
		const [item] = payload;
		const key = `${labelKey ?? item?.dataKey ?? item?.name ?? "value"}`;
		const itemConfig = getPayloadConfigFromPayload(config, item, key);
		const value = !labelKey && typeof label === "string" ? config[label]?.label ?? label : itemConfig?.label;
		if (labelFormatter) return /* @__PURE__ */ jsx("div", {
			className: cn("font-medium", labelClassName),
			children: labelFormatter(value, payload)
		});
		if (!value) return null;
		return /* @__PURE__ */ jsx("div", {
			className: cn("font-medium", labelClassName),
			children: value
		});
	}, [
		label,
		labelFormatter,
		payload,
		hideLabel,
		labelClassName,
		config,
		labelKey
	]);
	if (!active || !payload?.length) return null;
	const nestLabel = payload.length === 1 && indicator !== "dot";
	return /* @__PURE__ */ jsxs("div", {
		className: cn("grid min-w-[8rem] items-start gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl", className),
		children: [!nestLabel ? tooltipLabel : null, /* @__PURE__ */ jsx("div", {
			className: "grid gap-1.5",
			children: payload.filter((item) => item.type !== "none").map((item, index) => {
				const key = `${nameKey ?? item.name ?? item.dataKey ?? "value"}`;
				const itemConfig = getPayloadConfigFromPayload(config, item, key);
				const indicatorColor = color ?? item.payload?.fill ?? item.color;
				return /* @__PURE__ */ jsx("div", {
					className: cn("flex w-full flex-wrap items-stretch gap-2 [&>svg]:h-2.5 [&>svg]:w-2.5 [&>svg]:text-muted-foreground", indicator === "dot" && "items-center"),
					children: formatter && item?.value !== void 0 && item.name ? formatter(item.value, item.name, item, index, item.payload) : /* @__PURE__ */ jsxs(Fragment$1, { children: [itemConfig?.icon ? /* @__PURE__ */ jsx(itemConfig.icon, {}) : !hideIndicator && /* @__PURE__ */ jsx("div", {
						className: cn("shrink-0 rounded-[2px] border-(--color-border) bg-(--color-bg)", {
							"h-2.5 w-2.5": indicator === "dot",
							"w-1": indicator === "line",
							"w-0 border-[1.5px] border-dashed bg-transparent": indicator === "dashed",
							"my-0.5": nestLabel && indicator === "dashed"
						}),
						style: {
							"--color-bg": indicatorColor,
							"--color-border": indicatorColor
						}
					}), /* @__PURE__ */ jsxs("div", {
						className: cn("flex flex-1 justify-between leading-none", nestLabel ? "items-end" : "items-center"),
						children: [/* @__PURE__ */ jsxs("div", {
							className: "grid gap-1.5",
							children: [nestLabel ? tooltipLabel : null, /* @__PURE__ */ jsx("span", {
								className: "text-muted-foreground",
								children: itemConfig?.label ?? item.name
							})]
						}), item.value != null && /* @__PURE__ */ jsx("span", {
							className: "font-mono font-medium text-foreground tabular-nums",
							children: typeof item.value === "number" ? figure(typeof document === "undefined" ? "en" : document.documentElement.lang, item.value) : String(item.value)
						})]
					})] })
				}, index);
			})
		})]
	});
}
var ChartLegend = RechartsPrimitive.Legend;
function ChartLegendContent({ className, hideIcon = false, payload, verticalAlign = "bottom", nameKey }) {
	const { config } = useChart();
	if (!payload?.length) return null;
	return /* @__PURE__ */ jsx("div", {
		className: cn("flex items-center justify-center gap-4", verticalAlign === "top" ? "pb-3" : "pt-3", className),
		children: payload.filter((item) => item.type !== "none").map((item, index) => {
			const key = `${nameKey ?? item.dataKey ?? "value"}`;
			const itemConfig = getPayloadConfigFromPayload(config, item, key);
			return /* @__PURE__ */ jsxs("div", {
				className: cn("flex items-center gap-1.5 [&>svg]:h-3 [&>svg]:w-3 [&>svg]:text-muted-foreground"),
				children: [itemConfig?.icon && !hideIcon ? /* @__PURE__ */ jsx(itemConfig.icon, {}) : /* @__PURE__ */ jsx("div", {
					className: "h-2 w-2 shrink-0 rounded-[2px]",
					style: { backgroundColor: item.color }
				}), itemConfig?.label]
			}, index);
		})
	});
}
function getPayloadConfigFromPayload(config, payload, key) {
	if (typeof payload !== "object" || payload === null) return;
	const payloadPayload = "payload" in payload && typeof payload.payload === "object" && payload.payload !== null ? payload.payload : void 0;
	let configLabelKey = key;
	if (key in payload && typeof payload[key] === "string") configLabelKey = payload[key];
	else if (payloadPayload && key in payloadPayload && typeof payloadPayload[key] === "string") configLabelKey = payloadPayload[key];
	return configLabelKey in config ? config[configLabelKey] : config[key];
}
// resources/js/components/home/charts.tsx
/** A figure card's small trend line (§2 B): no axes, no tooltip - the figure above it says it. */
function Sparkline({ values }) {
	const config = { v: {
		label: "",
		color: colour(0)
	} };
	return /* @__PURE__ */ jsx(ChartContainer, {
		config,
		className: "aspect-auto h-10 w-full",
		"aria-hidden": "true",
		children: /* @__PURE__ */ jsx(LineChart, {
			data: values.map((v, i) => ({
				i,
				v
			})),
			margin: {
				top: 4,
				bottom: 4,
				left: 2,
				right: 2
			},
			children: /* @__PURE__ */ jsx(Line, {
				dataKey: "v",
				type: "monotone",
				stroke: "var(--color-v)",
				strokeWidth: 2,
				dot: false,
				isAnimationActive: false
			})
		})
	});
}
/** Sales and orders by day (§2 C): Sales and the period before's Sales on one axis, the orders on their own. */
function SeriesChart({ series, formats }) {
	const config = {};
	const colours = {
		sales: colour(0),
		orders: colour(1),
		before: "var(--tw-ink-subtle)"
	};
	for (const line of series.lines) config[line.key] = {
		label: line.label,
		color: colours[line.key] ?? colour(2)
	};
	const data = series.points.map((point) => ({
		at: point.at,
		...point.values
	}));
	return /* @__PURE__ */ jsx(ChartContainer, {
		config,
		className: "aspect-auto h-64 w-full",
		"aria-hidden": "true",
		children: /* @__PURE__ */ jsxs(LineChart, {
			data,
			margin: {
				top: 8,
				left: 4,
				right: 4
			},
			children: [
				/* @__PURE__ */ jsx(CartesianGrid, { vertical: false }),
				/* @__PURE__ */ jsx(XAxis, {
					dataKey: "at",
					tickLine: false,
					axisLine: false,
					tickMargin: 8,
					minTickGap: 24,
					reversed: formats.rtl,
					tickFormatter: (at) => formats.at(at)
				}),
				/* @__PURE__ */ jsx(YAxis, {
					yAxisId: "money",
					tickLine: false,
					axisLine: false,
					width: 56,
					orientation: formats.rtl ? "right" : "left",
					tickFormatter: (v) => formats.moneyTick(v)
				}),
				/* @__PURE__ */ jsx(YAxis, {
					yAxisId: "count",
					tickLine: false,
					axisLine: false,
					width: 40,
					orientation: formats.rtl ? "left" : "right",
					allowDecimals: false,
					tickFormatter: (v) => formats.countTick(v)
				}),
				/* @__PURE__ */ jsx(ChartTooltip, { content: /* @__PURE__ */ jsx(ChartTooltipContent, {
					labelFormatter: (_, payload) => formats.at(String((payload[0]?.payload)?.at ?? "")),
					formatter: (value, name) => /* @__PURE__ */ jsxs("div", {
						className: "flex w-full items-center justify-between gap-3",
						children: [/* @__PURE__ */ jsx("span", {
							className: "text-muted-foreground",
							children: config[String(name)]?.label
						}), /* @__PURE__ */ jsx("span", {
							className: "tw-figure text-foreground",
							children: formats.value(String(name), Number(value))
						})]
					})
				}) }),
				/* @__PURE__ */ jsx(ChartLegend, { content: /* @__PURE__ */ jsx(ChartLegendContent, {}) }),
				series.lines.map((line) => /* @__PURE__ */ jsx(Line, {
					yAxisId: line.unit === "money" ? "money" : "count",
					dataKey: line.key,
					type: "monotone",
					stroke: `var(--color-${line.key})`,
					strokeWidth: 2,
					strokeDasharray: line.dotted ? "4 4" : void 0,
					dot: false,
					connectNulls: false,
					isAnimationActive: false
				}, line.key))
			]
		})
	});
}
/** Individuals and companies (§2 D): a ring of their shares, the total in its middle. */
function RingChart({ split, total }) {
	const config = {};
	split.parts.forEach((part, index) => {
		config[`p${index}`] = {
			label: part.label,
			color: colour(index)
		};
	});
	const data = split.parts.map((part, index) => ({
		key: `p${index}`,
		value: part.value.value,
		fill: `var(--color-p${index})`
	}));
	const empty = data.every((part) => part.value === 0);
	return /* @__PURE__ */ jsx(ChartContainer, {
		config,
		className: "mx-auto aspect-square h-48",
		"aria-hidden": "true",
		children: /* @__PURE__ */ jsx(PieChart, { children: /* @__PURE__ */ jsxs(Pie, {
			data: empty ? [{
				key: "none",
				value: 1,
				fill: "var(--tw-line)"
			}] : data,
			dataKey: "value",
			nameKey: "key",
			innerRadius: 56,
			outerRadius: 80,
			strokeWidth: 2,
			isAnimationActive: false,
			children: [(empty ? [{
				key: "none",
				fill: "var(--tw-line)"
			}] : data).map((part) => /* @__PURE__ */ jsx(Cell, { fill: part.fill }, part.key)), /* @__PURE__ */ jsx(Label, { content: ({ viewBox }) => viewBox && "cx" in viewBox && "cy" in viewBox ? /* @__PURE__ */ jsx("text", {
				x: viewBox.cx,
				y: viewBox.cy,
				textAnchor: "middle",
				dominantBaseline: "middle",
				className: "fill-foreground text-label-14",
				children: total
			}) : null })]
		}) })
	});
}
/** One split bar (§2 I): the whole's parts side by side, by their shares. */
function SplitBarChart({ split, rtl }) {
	const config = {};
	const row = { name: split.label };
	split.parts.forEach((part, index) => {
		config[`p${index}`] = {
			label: part.label,
			color: colour(index)
		};
		row[`p${index}`] = part.share;
	});
	return /* @__PURE__ */ jsx(ChartContainer, {
		config,
		className: "aspect-auto h-8 w-full",
		"aria-hidden": "true",
		children: /* @__PURE__ */ jsxs(BarChart, {
			data: [row],
			layout: "vertical",
			margin: {
				top: 0,
				bottom: 0,
				left: 0,
				right: 0
			},
			barCategoryGap: 0,
			children: [
				/* @__PURE__ */ jsx(XAxis, {
					type: "number",
					hide: true,
					domain: [0, 100],
					reversed: rtl
				}),
				/* @__PURE__ */ jsx(YAxis, {
					type: "category",
					dataKey: "name",
					hide: true
				}),
				split.parts.map((part, index) => /* @__PURE__ */ jsx(Bar, {
					dataKey: `p${index}`,
					stackId: "whole",
					fill: `var(--color-p${index})`,
					isAnimationActive: false
				}, part.label))
			]
		})
	});
}
export { RingChart, SeriesChart, Sparkline, SplitBarChart };
