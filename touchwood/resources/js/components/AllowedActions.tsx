import { usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemMedia, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { figure } from '@/lib/digits';
import { tone } from '@/lib/tones';
import type { SharedProps } from '@/types/page';

/*
| What a role allows, area by area (frontend.md §3.3 C2, §3.4 D2), on one staff member's page and
| on one role's page alike - one block, so the two pages never drift apart.
|
| Each business area is a shadcn Card whose **header reads as a header, not as one more row** (the
| owner, 2026-10-06: "the sections same as the inside cells, i cant know the difference"): a sunken
| band with the area's name in the heading's weight and how many actions it holds, as settings and
| permission lists group their rows (Geist's Card header; GitHub's and Vercel's role pages). Its
| actions are a shadcn Item group, each marked allowed with a check: the action's name as the item's
| title (Geist's Entity: a label and one line of metadata), and the line under it - where it reaches
| - as its description. An action given stores of its own wears a Badge, and the Badge says what that
| means in a Tooltip (Geist: a badge that names a limit is paired with a tooltip); the badge is a
| button, so a keyboard reaches the tooltip too.
*/

export type AllowedGroup = { key: string; label: string };

export type AllowedRow = {
    key: string;
    group: string;
    label: string;
    /** The line under the action: where it reaches. */
    detail?: string;
    badge?: { text: string; hint: string };
};

export function AllowedActions({ groups, rows }: { groups: AllowedGroup[]; rows: AllowedRow[] }) {
    const { locale } = usePage<SharedProps>().props;

    return (
        <div className="grid gap-4">
            {groups.map((group) => {
                const inGroup = rows.filter((row) => row.group === group.key);

                if (inGroup.length === 0) {
                    return null;
                }

                return (
                    <Card key={group.key} className="material-base gap-0 overflow-hidden border-0 py-0" data-test={`allows-${group.key}`}>
                        <CardHeader className="flex items-center justify-between gap-3 border-b border-line bg-surface-sunken px-4 py-3 [.border-b]:pb-3">
                            <CardTitle className="text-label-14 font-semibold text-ink">
                                <h3>{group.label}</h3>
                            </CardTitle>
                            {/* How many the area holds; the list under it names them. */}
                            <Badge className={`tw-figure ${tone('gray-subtle')}`} aria-hidden="true">
                                {figure(locale, inGroup.length)}
                            </Badge>
                        </CardHeader>
                        <CardContent className="px-0">
                            <ItemGroup>
                                {inGroup.map((row, index) => (
                                    <div key={row.key} role="listitem">
                                        {index === 0 ? null : <ItemSeparator />}
                                        <Item size="sm" className="rounded-none">
                                            <ItemMedia>
                                                <Check aria-hidden="true" className="size-4 text-good" />
                                            </ItemMedia>
                                            <ItemContent className="gap-0.5">
                                                <ItemTitle className="flex-wrap text-copy-14 font-normal text-ink">
                                                    {row.label}
                                                    {row.badge === undefined ? null : (
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge asChild className={tone('amber-subtle')}>
                                                                    <button type="button" aria-label={`${row.badge.text}: ${row.badge.hint}`}>
                                                                        {row.badge.text}
                                                                    </button>
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>{row.badge.hint}</TooltipContent>
                                                        </Tooltip>
                                                    )}
                                                </ItemTitle>
                                                {row.detail === undefined || row.detail === '' ? null : (
                                                    <ItemDescription className="text-copy-13 text-ink-muted">{row.detail}</ItemDescription>
                                                )}
                                            </ItemContent>
                                        </Item>
                                    </div>
                                ))}
                            </ItemGroup>
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}
