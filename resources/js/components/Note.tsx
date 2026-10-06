import type { ComponentType, ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import { cn } from 'cn';
import { Alert, AlertDescription } from '@/components/ui/alert';

/*
| Geist's Note (frontend.md §1.10, §1.11) on shadcn's Alert: an inline, persistent message next to
| what it describes. Geist's colours are given through `className`; shadcn's Alert is unchanged.
|
| Geist's rules, from its page: it stays until its cause is gone, has no close button and holds at
| most one action. Its label is a 1-2 word Title Case topic ("Plan Limit"), never "Note" or "Heads
| up"; its content one active sentence. There is no "info" variant: the default is information,
| `secondary` neutral.
|
| shadcn's Alert always says role="alert", which a screen reader announces at once. Geist announces
| only a message that appears after something the person did (`alert`); any other note is a plain
| note, so it says role="note" instead.
*/

export type NoteVariant = 'default' | 'secondary' | 'success' | 'warning' | 'error';

const LOOK: Record<NoteVariant, { box: string; icon: ComponentType<{ 'aria-hidden': 'true' }> }> = {
    default: { box: 'bg-info-soft text-info shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-info)_25%,transparent)]', icon: Info },
    secondary: { box: 'bg-surface-sunken text-ink-muted shadow-[0_0_0_1px_var(--tw-line)]', icon: Info },
    success: { box: 'bg-good-soft text-good shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-good)_25%,transparent)]', icon: CheckCircle2 },
    warning: { box: 'bg-warn-soft text-warn shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-warn)_25%,transparent)]', icon: AlertTriangle },
    error: { box: 'bg-bad-soft text-bad shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-bad)_25%,transparent)]', icon: XCircle },
};

type NoteProps = {
    variant?: NoteVariant;
    /** 1-2 Title Case words naming the topic. No period. */
    label?: ReactNode;
    children: ReactNode;
    /** One inline action, never two. */
    action?: ReactNode;
    size?: 'small' | 'medium';
    /** Announce it: for a message that appears after something the person did. */
    alert?: boolean;
    className?: string;
    'data-test'?: string;
};

export function Note({ variant = 'default', label, children, action, size = 'medium', alert = false, className, ...rest }: NoteProps) {
    const look = LOOK[variant];
    const Icon = look.icon;

    return (
        <Alert
            {...rest}
            role={alert ? 'alert' : 'note'}
            className={cn('rounded-[var(--tw-radius)] border-0', size === 'small' ? 'px-3 py-2' : 'px-4 py-3', look.box, className)}
        >
            {/* A direct child: shadcn's Alert lays out its icon column only for an svg it holds itself. */}
            <Icon aria-hidden="true" />
            <AlertDescription className={cn('flex items-start gap-3 text-current', size === 'small' ? 'text-copy-13' : 'text-copy-14')}>
                <span className="min-w-0 flex-1">
                    {label === undefined ? null : <span className="me-1 font-semibold">{label}:</span>}
                    {children}
                </span>
                {action === undefined ? null : <span className="shrink-0">{action}</span>}
            </AlertDescription>
        </Alert>
    );
}
