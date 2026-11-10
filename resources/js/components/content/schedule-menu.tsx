import { lazy, Suspense, useRef, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { MoreButton } from '@/components/RowMenu';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

/*
| A scheduled item's row menu (content.md §4.1, §4.4 W3, W6): Edit; End Now… while live; Back to Draft while scheduled;
| Delete… only as a draft, last. Each destructive one asks first, its button repeating the title's verb. A banner and a
| pop-up share it, each with its own words.
*/

// Its question loads when asked: never on first paint, so React.lazy is safe under SSR (lesson 180).
const ScheduleConfirm = lazy(() => import('./schedule-confirm').then((module) => ({ default: module.ScheduleConfirm })));

export type ScheduleWords = {
    edit: string;
    end: string;
    draft: string;
    delete: string;
    endTitle: string;
    endBody: string;
    endConfirm: string;
    deleteTitle: string;
    deleteBody: string;
    deleteConfirm: string;
};

export function ScheduleMenu({ id, name, state, base, words, extra }: { id: string; name: string; state: string; base: string; words: ScheduleWords; extra?: ReactNode }) {
    const more = useRef<HTMLButtonElement>(null);
    const [asking, setAsking] = useState<'end' | 'delete' | null>(null);
    const [busy, setBusy] = useState(false);
    const post = (action: string) =>
        router.post(
            `${base}/${id}/${action}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setAsking(null);
                },
            },
        );

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <MoreButton ref={more} name={name} data-test={`more-${id}`} />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-44">
                    <DropdownMenuItem asChild>
                        <Link href={`${base}/${id}`} data-test="menu-edit">
                            {words.edit}
                        </Link>
                    </DropdownMenuItem>
                    {extra}
                    {state === 'SCHEDULED' ? (
                        <DropdownMenuItem onSelect={() => post('draft')} data-test="menu-draft">
                            {words.draft}
                        </DropdownMenuItem>
                    ) : null}
                    {state === 'LIVE' || state === 'DRAFT' ? <DropdownMenuSeparator /> : null}
                    {state === 'LIVE' ? (
                        <DropdownMenuItem variant="destructive" onSelect={() => setAsking('end')} data-test="menu-end">{`${words.end}…`}</DropdownMenuItem>
                    ) : null}
                    {state === 'DRAFT' ? (
                        <DropdownMenuItem variant="destructive" onSelect={() => setAsking('delete')} data-test="menu-delete">{`${words.delete}…`}</DropdownMenuItem>
                    ) : null}
                </DropdownMenuContent>
            </DropdownMenu>
            {asking === null ? null : (
                <Suspense fallback={null}>
                    <ScheduleConfirm
                        returnFocusTo={more}
                        title={asking === 'end' ? words.endTitle : words.deleteTitle}
                        description={asking === 'end' ? words.endBody : words.deleteBody}
                        label={asking === 'end' ? words.endConfirm : words.deleteConfirm}
                        busy={busy}
                        onConfirm={() => post(asking)}
                        onClose={() => setAsking(null)}
                        test={`confirm-${asking}`}
                    />
                </Suspense>
            )}
        </>
    );
}
