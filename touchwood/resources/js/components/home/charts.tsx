import { Bar, BarChart, CartesianGrid, Cell, Label, Line, LineChart, Pie, PieChart, XAxis, YAxis } from 'recharts';
import { ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { colour } from '@/components/home/palette';
import type { HomeSeries, HomeSplit } from '@/types/generated/Modules/Platform/Public/Dto';

/*
| The admin home's charts (dashboard.md §2 B, C, D, I; §4), on shadcn's Chart (Recharts v3) - **loaded just after
| the page shows**, by its own dynamic import from the page (§7: 127.9 KB gzipped, which no page's budget could
| carry). Nothing here runs on the server: the page draws each chart's place at its size until this arrives.
|
| The lines and parts take our tokens (brand navy, copper, then the information blue and the greys), read from the
| CSS variables as shadcn's Chart does, so light, dark and a campaign theme all repaint them; the period before is
| dotted, in a grey. Each chart's figures are also on the page in words, for screen readers (the page's own hidden
| table): the drawing itself is hidden from them.
*/

export type Formats = {
    /** A point's day or month as the page writes it. */
    at: (at: string) => string;
    /** A line's value at full length, as the tooltip writes it. */
    value: (key: string, value: number) => string;
    /** A money line's value, short, for its axis. */
    moneyTick: (value: number) => string;
    /** A count, short, for its axis. */
    countTick: (value: number) => string;
    rtl: boolean;
};

/** A figure card's small trend line (§2 B): no axes, no tooltip - the figure above it says it. */
export function Sparkline({ values }: { values: number[] }) {
    const config = { v: { label: '', color: colour(0) } } satisfies ChartConfig;

    return (
        <ChartContainer config={config} className="aspect-auto h-10 w-full" aria-hidden="true">
            <LineChart data={values.map((v, i) => ({ i, v }))} margin={{ top: 4, bottom: 4, left: 2, right: 2 }}>
                <Line dataKey="v" type="monotone" stroke="var(--color-v)" strokeWidth={2} dot={false} isAnimationActive={false} />
            </LineChart>
        </ChartContainer>
    );
}

/** Sales and orders by day (§2 C): Sales and the period before's Sales on one axis, the orders on their own. */
export function SeriesChart({ series, formats }: { series: HomeSeries; formats: Formats }) {
    const config: ChartConfig = {};
    const colours: Record<string, string> = { sales: colour(0), orders: colour(1), before: 'var(--tw-ink-subtle)' };

    for (const line of series.lines) {
        config[line.key] = { label: line.label, color: colours[line.key] ?? colour(2) };
    }

    const data = series.points.map((point) => ({ at: point.at, ...point.values }));

    return (
        <ChartContainer config={config} className="aspect-auto h-64 w-full" aria-hidden="true">
            <LineChart data={data} margin={{ top: 8, left: 4, right: 4 }}>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="at" tickLine={false} axisLine={false} tickMargin={8} minTickGap={24} reversed={formats.rtl} tickFormatter={(at: string) => formats.at(at)} />
                <YAxis yAxisId="money" tickLine={false} axisLine={false} width={56} orientation={formats.rtl ? 'right' : 'left'} tickFormatter={(v: number) => formats.moneyTick(v)} />
                <YAxis yAxisId="count" tickLine={false} axisLine={false} width={40} orientation={formats.rtl ? 'left' : 'right'} allowDecimals={false} tickFormatter={(v: number) => formats.countTick(v)} />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_, payload) => formats.at(String((payload[0]?.payload as { at?: string } | undefined)?.at ?? ''))}
                            formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                    <span className="text-muted-foreground">{config[String(name)]?.label}</span>
                                    <span className="tw-figure text-foreground">{formats.value(String(name), Number(value))}</span>
                                </div>
                            )}
                        />
                    }
                />
                <ChartLegend content={<ChartLegendContent />} />
                {series.lines.map((line) => (
                    <Line
                        key={line.key}
                        yAxisId={line.unit === 'money' ? 'money' : 'count'}
                        dataKey={line.key}
                        type="monotone"
                        stroke={`var(--color-${line.key})`}
                        strokeWidth={2}
                        strokeDasharray={line.dotted ? '4 4' : undefined}
                        dot={false}
                        connectNulls={false}
                        isAnimationActive={false}
                    />
                ))}
            </LineChart>
        </ChartContainer>
    );
}

/** Individuals and companies (§2 D): a ring of their shares, the total in its middle. */
export function RingChart({ split, total }: { split: HomeSplit; total: string }) {
    const config: ChartConfig = {};
    split.parts.forEach((part, index) => {
        config[`p${index}`] = { label: part.label, color: colour(index) };
    });
    const data = split.parts.map((part, index) => ({ key: `p${index}`, value: part.value.value, fill: `var(--color-p${index})` }));
    const empty = data.every((part) => part.value === 0);

    return (
        <ChartContainer config={config} className="mx-auto aspect-square h-48" aria-hidden="true">
            <PieChart>
                <Pie data={empty ? [{ key: 'none', value: 1, fill: 'var(--tw-line)' }] : data} dataKey="value" nameKey="key" innerRadius={56} outerRadius={80} strokeWidth={2} isAnimationActive={false}>
                    {(empty ? [{ key: 'none', fill: 'var(--tw-line)' }] : data).map((part) => (
                        <Cell key={part.key} fill={part.fill} />
                    ))}
                    <Label
                        content={({ viewBox }) =>
                            viewBox && 'cx' in viewBox && 'cy' in viewBox ? (
                                <text x={viewBox.cx} y={viewBox.cy} textAnchor="middle" dominantBaseline="middle" className="fill-foreground text-label-14">
                                    {total}
                                </text>
                            ) : null
                        }
                    />
                </Pie>
            </PieChart>
        </ChartContainer>
    );
}

/** One split bar (§2 I): the whole's parts side by side, by their shares. */
export function SplitBarChart({ split, rtl }: { split: HomeSplit; rtl: boolean }) {
    const config: ChartConfig = {};
    const row: Record<string, number | string> = { name: split.label };
    split.parts.forEach((part, index) => {
        config[`p${index}`] = { label: part.label, color: colour(index) };
        row[`p${index}`] = part.share;
    });

    return (
        <ChartContainer config={config} className="aspect-auto h-8 w-full" aria-hidden="true">
            <BarChart data={[row]} layout="vertical" margin={{ top: 0, bottom: 0, left: 0, right: 0 }} barCategoryGap={0}>
                <XAxis type="number" hide domain={[0, 100]} reversed={rtl} />
                <YAxis type="category" dataKey="name" hide />
                {split.parts.map((part, index) => (
                    <Bar key={part.label} dataKey={`p${index}`} stackId="whole" fill={`var(--color-p${index})`} isAnimationActive={false} />
                ))}
            </BarChart>
        </ChartContainer>
    );
}
