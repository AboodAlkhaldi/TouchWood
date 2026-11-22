import { useEffect, useRef, useState } from 'react';
import { Check, Copy, X } from 'lucide-react';
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
|   hears "Copied" from a polite status line beside it;
| - a copy the browser refuses (an insecure origin, a denied permission) is said too, the same way:
|   a cross for a moment, and "Couldn't copy" with what to do instead - never a silent button.
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
    const [said, setSaid] = useState<'copied' | 'failed' | null>(null);
    const timer = useRef<number | null>(null);

    useEffect(() => () => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }
    }, []);

    async function copy() {
        let result: 'copied' | 'failed' = 'copied';

        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // The text stays where it is, to select by hand; the button says so.
            result = 'failed';
        }

        setSaid(result);

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => setSaid(null), 2000);
    }

    return (
        <>
            <Button type="button" variant="ghost" size="icon-sm" aria-label={label} title={label} onClick={copy} className={className} data-test="copy-button">
                {said === 'copied' ? <Check aria-hidden="true" /> : said === 'failed' ? <X aria-hidden="true" className="text-bad" /> : <Copy aria-hidden="true" />}
            </Button>
            <span role="status" className="sr-only">
                {said === 'copied' ? t('ui.copied') : said === 'failed' ? t('ui.copy_failed') : ''}
            </span>
        </>
    );
}
