import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Item, ItemContent, ItemDescription, ItemGroup, ItemSeparator, ItemTitle } from '@/components/ui/item';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { tone } from '@/lib/tones';

/*
| What a role allows, area by area (frontend.md §3.3 C2, §3.4 D2), on one staff member's page and
| on one role's page alike - one block, so the two pages never drift apart.
|
| Each business area is a shadcn Card, its actions a shadcn Item group: the action's name as the
| item's title (Geist's Entity: a label and one line of metadata), and the line under it - where it
| reaches - as its description. An action given stores of its own wears a Badge, and the Badge says
| what that means in a Tooltip (Geist: a badge that names a limit is paired with a tooltip); the
| badge is a button, so a keyboard reaches the tooltip too.
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
    return (
        <div className="grid gap-3">
            {groups.map((group) => {
                const inGroup = rows.filter((row) => row.group === group.key);

                if (inGroup.length === 0) {
                    return null;
                }

                return (
                    <Card key={group.key} className="material-base gap-0 border-0 py-0" data-test={`allows-${group.key}`}>
                        <CardHeader className="border-b border-line px-4 py-2 [.border-b]:pb-2">
                            <CardTitle className="text-label-13 font-medium text-ink-muted">
                                <h3>{group.label}</h3>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-0">
                            <ItemGroup>
                                {inGroup.map((row, index) => (
                                    <div key={row.key} role="listitem">
                                        {index === 0 ? null : <ItemSeparator />}
                                        <Item size="sm" className="rounded-none">
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
