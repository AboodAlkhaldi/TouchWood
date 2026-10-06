import { type ComponentProps, type MouseEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslator } from '@/lib/t';

/*
| shadcn's Button with Geist's two rules that its own examples do not follow (frontend.md §1.11:
| "where the two disagree, Geist's rule wins: a loading button stays focusable ... an action that
| cannot be done is shown disabled, with the reason"):
|
| - `loading` keeps the button in place and focusable, says it is busy (aria-busy), shows shadcn's
|   Spinner and ignores presses - shadcn's `button-loading` example disables it instead, which
|   takes it out of the keyboard's reach while the person waits on it;
| - `disabledReason` keeps it reachable (aria-disabled, never `disabled`) with the reason as its
|   tooltip, so the reason can be read.
|
| Everything else is shadcn's Button as the CLI wrote it: its variants, sizes and `asChild`.
*/

type Props = ComponentProps<typeof Button> & {
    loading?: boolean;
    /** Why it cannot be done, one sentence ending with a period; shown as its tooltip. */
    disabledReason?: string;
};

export function ActionButton({ loading = false, disabledReason, disabled = false, onClick, children, ...rest }: Props) {
    const t = useTranslator();
    const [tip, setTip] = useState(false);
    const unavailable = disabled || disabledReason !== undefined;
    const inert = unavailable || loading;

    const button = (
        <Button
            {...rest}
            aria-disabled={inert || undefined}
            aria-busy={loading || undefined}
            data-loading={loading || undefined}
            // Looks unavailable without leaving the keyboard's reach, as `disabled` would.
            className={unavailable ? `${rest.className ?? ''} cursor-not-allowed opacity-50` : rest.className}
            onClick={(event: MouseEvent<HTMLButtonElement>) => {
                if (inert) {
                    // Still focusable, so the busy state and the reason can be read; never acting.
                    event.preventDefault();

                    return;
                }
                onClick?.(event);
            }}
        >
            {loading ? <Spinner aria-label={t('ui.loading')} /> : null}
            {children}
        </Button>
    );

    // Always inside its tooltip, shut while there is no reason: a button that gains or loses one
    // (Resend Code, as its wait runs out) keeps its place in the tree and the keyboard focus on it.
    // Always controlled, too: switching Radix between controlled and not keeps a stale "open" that
    // could show a returning reason without a hover (the batch B review).
    return (
        <Tooltip open={disabledReason !== undefined && tip} onOpenChange={setTip}>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            {disabledReason === undefined ? null : <TooltipContent>{disabledReason}</TooltipContent>}
        </Tooltip>
    );
}
