import { type ClipboardEvent, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { cn } from 'cn';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/*
| Geist's Middle Truncate (frontend.md §1.11: one of Geist's pieces shadcn lacks), built from
| Geist's own page: cuts a string in the middle, keeping its start and its end - for a file name,
| the name and the extension.
|
| Geist's rules, from its page:
| - one ellipsis glyph (…), never three periods;
| - it follows its container's width, so it measures again when the width changes;
| - copying it gives the whole string, never the shortened one;
| - the whole string reaches assistive tech, and a Tooltip shows it to everyone else;
| - it is never wrapped in another text-overflow ellipsis.
|
| The width is measured with the element's own font on a canvas, so nothing is drawn twice to find
| out what fits.
*/

// The server has no layout; the measuring runs in the browser only.
const useBrowserLayoutEffect = typeof window === 'undefined' ? useEffect : useLayoutEffect;

let canvas: HTMLCanvasElement | null = null;

function widthOf(text: string, font: string): number {
    canvas ??= document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (context === null) {
        return 0;
    }

    context.font = font;

    return context.measureText(text).width;
}

/** The longest head…tail that fits, the head taking the odd character. */
function fitted(value: string, room: number, font: string): string {
    if (room <= 0 || widthOf(value, font) <= room) {
        return value;
    }

    let low = 0;
    let high = value.length - 1;

    while (low < high) {
        const middle = Math.ceil((low + high) / 2);

        if (widthOf(cut(value, middle), font) <= room) {
            low = middle;
        } else {
            high = middle - 1;
        }
    }

    return cut(value, low);
}

function cut(value: string, keep: number): string {
    const head = Math.ceil(keep / 2);
    const tail = keep - head;

    return `${value.slice(0, head)}…${tail === 0 ? '' : value.slice(value.length - tail)}`;
}

export function MiddleTruncate({ value, className }: { value: string; className?: string }) {
    const box = useRef<HTMLSpanElement>(null);
    const [shown, setShown] = useState(value);
    const [tip, setTip] = useState(false);

    useBrowserLayoutEffect(() => {
        const element = box.current;

        if (element === null) {
            return;
        }

        const measure = () => {
            const style = window.getComputedStyle(element);
            setShown(fitted(value, element.clientWidth, `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`));
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        // Measured again once the web fonts are in: before, the fallback font's widths were used.
        let alive = true;
        void document.fonts?.ready.then(() => (alive ? measure() : undefined));

        return () => {
            alive = false;
            observer.disconnect();
        };
    }, [value]);

    // Copying any of it copies all of it (Geist: "Copying truncated text yields the full original
    // string") - when the selection is inside the name; a selection running past it, a whole table
    // row, is copied as the browser would.
    const copyWhole = (event: ClipboardEvent<HTMLSpanElement>) => {
        const selection = window.getSelection();
        const element = box.current;

        if (selection === null || element === null || !element.contains(selection.anchorNode) || !element.contains(selection.focusNode)) {
            return;
        }

        event.preventDefault();
        event.clipboardData.setData('text/plain', value);
    };

    const text = (
        // Cut, it takes focus, so a keyboard can open the tooltip with the whole name too.
        <span
            ref={box}
            onCopy={copyWhole}
            tabIndex={shown !== value ? 0 : undefined}
            className={cn('block min-w-0 overflow-hidden whitespace-nowrap rounded-[var(--tw-radius-sm)] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50', className)}
        >
            <span aria-hidden="true">{shown}</span>
            <span className="sr-only">{value}</span>
        </span>
    );

    // One tree whether or not it is cut, so the element being measured is never swapped out; the
    // tooltip opens only when there is something it hides.
    return (
        <Tooltip open={shown !== value && tip} onOpenChange={setTip}>
            <TooltipTrigger asChild>{text}</TooltipTrigger>
            <TooltipContent>{value}</TooltipContent>
        </Tooltip>
    );
}
