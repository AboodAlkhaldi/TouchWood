import { useEffect, useRef, useState } from 'react';
import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslator } from '@/lib/t';

/*
| Geist's Copy Button (frontend.md §1.11: one of Geist's pieces shadcn lacks), built from Geist's
| own page and rules on shadcn's Button: a button that copies a given string to the clipboard and
| says it did.
|
| Geist's rules, from its pages:
| - an icon-only button names the action and the target ("Copy deployment URL"), never the icon
|   ("Copy") - so the caller passes the name;
| - it gives feedback when copied: the icon turns to a check for a moment, and a screen reader
|   hears "Copied" from a polite status line beside it.
*/

type Props = {
    /** The exact text that goes to the clipboard. */
    text: string;
    /** What it copies, as an action: "Copy Error Details". */
    label: string;
    className?: string;
};

export function CopyButton({ text, label, className }: Props) {
    const t = useTranslator();
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);

    useEffect(() => () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }
    }, []);

    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // A browser that refuses the clipboard (an insecure origin, a denied permission) leaves
            // the text where it is to select by hand; nothing is claimed.
            return;
        }

        setCopied(true);

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => setCopied(false), 2000);
    }

    return (
        <>
            <Button type="button" variant="ghost" size="icon-sm" aria-label={label} title={label} onClick={copy} className={className} data-test="copy-button">
                {copied ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
            </Button>
            <span role="status" className="sr-only">
                {copied ? t('ui.copied') : ''}
            </span>
        </>
    );
}
