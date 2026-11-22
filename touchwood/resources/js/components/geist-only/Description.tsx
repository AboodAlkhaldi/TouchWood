import type { ReactNode } from 'react';
import { CircleAlert } from 'lucide-react';
import { cn } from 'cn';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/*
| Geist's Description (frontend.md §1.11: one of Geist's pieces shadcn lacks), built from Geist's
| own page: definition-list metadata, a short Title Case key over one value.
|
| Geist's rules, from that page:
| - it renders <dl>/<dt>/<dd>, so a screen reader announces each key with its value;
| - the title is a Title Case noun; the content is sentence case, unless it is a literal - an id,
|   a timestamp - kept as it is;
| - a tooltip only when the title alone is ambiguous, one sentence ending with a period;
| - nothing interactive in the title: buttons, menus and links belong in the content.
| An unknown value is an em dash (§1.10, the writing rules). The tooltip is shadcn's.
*/

export type DescriptionItem = {
    title: ReactNode;
    content: ReactNode;
    /** One sentence, ending with a period, when the title alone is ambiguous. */
    tooltip?: string;
    'data-test'?: string;
};

export function Description({ items, columns = 2 }: { items: DescriptionItem[]; columns?: 1 | 2 | 3 }) {
    return (
        <dl className={cn('grid gap-x-6 gap-y-4', columns === 1 ? 'grid-cols-1' : columns === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3')}>
            {items.map((item, index) => (
                <div key={index} className="grid content-start gap-1" data-test={item['data-test']}>
                    <dt className="flex items-center gap-1 text-label-13 text-ink-muted">
                        {item.title}
                        {item.tooltip === undefined ? null : (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button type="button" aria-label={item.tooltip} className="text-ink-subtle">
                                        <CircleAlert aria-hidden="true" className="size-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>{item.tooltip}</TooltipContent>
                            </Tooltip>
                        )}
                    </dt>
                    <dd className="text-label-14 text-ink">
                        {item.content === null || item.content === undefined || item.content === '' ? (
                            <span className="text-ink-subtle">—</span>
                        ) : (
                            item.content
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
