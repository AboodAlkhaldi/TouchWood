import { useEffect, useState, type ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import { cx } from './cx';

/*
| Geist's Badge, Note and Kbd (frontend.md 1.10).
|
| Badge: short, static metadata beside the thing it describes - a status, a role. Title Case, one
| word, two at most ("Approved", "Waiting"). Colour carries meaning (green healthy, red error, amber
| warning, blue information, grey neutral) and never alone: the word is always there. `-subtle`
| tones it down on dense rows. Not clickable - an action is a Button.
|
| Note: an inline, persistent message next to what it describes; it stays until its cause is gone,
| has no close button, holds at most one action. Its label is a 1-2 word Title Case topic
| ("Plan Limit"), never "Note" or "Heads up"; its content one active sentence.
*/

export type BadgeVariant =
    | 'gray'
    | 'blue'
    | 'green'
    | 'amber'
    | 'red'
    | 'gray-subtle'
    | 'blue-subtle'
    | 'green-subtle'
    | 'amber-subtle'
    | 'red-subtle';

const BADGE: Record<BadgeVariant, string> = {
    gray: 'bg-ink-muted text-surface',
    blue: 'bg-info text-ink-on-brand',
    green: 'bg-good text-ink-on-brand',
    amber: 'bg-warn text-ink-on-brand',
    red: 'bg-bad text-ink-on-brand',
    'gray-subtle': 'bg-surface-sunken text-ink-muted',
    'blue-subtle': 'bg-info-soft text-info',
    'green-subtle': 'bg-good-soft text-good',
    'amber-subtle': 'bg-warn-soft text-warn',
    'red-subtle': 'bg-bad-soft text-bad',
};

const BADGE_SIZE = { small: 'h-5 px-1.5 text-label-12', medium: 'h-6 px-2.5 text-label-12', large: 'h-8 px-3 text-label-14' };

type BadgeProps = {
    variant?: BadgeVariant;
    size?: keyof typeof BADGE_SIZE;
    icon?: ReactNode;
    /** For an icon-only or ambiguous badge: what it means. */
    title?: string;
    children?: ReactNode;
    'data-test'?: string;
};

export function Badge({ variant = 'gray-subtle', size = 'medium', icon, children, ...rest }: BadgeProps) {
    return (
        <span
            {...rest}
            className={cx('inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-[var(--tw-radius-pill)] font-medium [&_svg]:size-3', BADGE[variant], BADGE_SIZE[size])}
        >
            {icon}
            {children}
        </span>
    );
}

export type NoteVariant = 'default' | 'secondary' | 'success' | 'warning' | 'error';

const NOTE: Record<NoteVariant, { box: string; icon: ReactNode }> = {
    default: { box: 'bg-info-soft text-info shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-info)_25%,transparent)]', icon: <Info /> },
    secondary: { box: 'bg-surface-sunken text-ink-muted shadow-[0_0_0_1px_var(--tw-line)]', icon: <Info /> },
    success: { box: 'bg-good-soft text-good shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-good)_25%,transparent)]', icon: <CheckCircle2 /> },
    warning: { box: 'bg-warn-soft text-warn shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-warn)_25%,transparent)]', icon: <AlertTriangle /> },
    error: { box: 'bg-bad-soft text-bad shadow-[0_0_0_1px_color-mix(in_oklab,var(--tw-bad)_25%,transparent)]', icon: <XCircle /> },
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
    'data-test'?: string;
};

export function Note({ variant = 'default', label, children, action, size = 'medium', alert = false, ...rest }: NoteProps) {
    const look = NOTE[variant];

    return (
        <div
            {...rest}
            role={alert ? 'alert' : undefined}
            className={cx(
                'flex items-start gap-2.5 rounded-[var(--tw-radius)] [&_svg]:size-4 [&_svg]:shrink-0',
                size === 'small' ? 'px-3 py-2 text-copy-13' : 'px-4 py-3 text-copy-14',
                look.box,
            )}
        >
            <span aria-hidden="true" className="mt-0.5">
                {look.icon}
            </span>
            <div className="min-w-0 flex-1">
                {label === undefined ? null : <span className="me-1 font-semibold">{label}:</span>}
                {children}
            </div>
            {action === undefined ? null : <div className="shrink-0">{action}</div>}
        </div>
    );
}

/** One key - a letter, a digit or a named key. Modifiers are props, and Ctrl is shown off a Mac. */
export function Kbd({ children, meta, shift, alt, ctrl, small }: { children?: ReactNode; meta?: boolean; shift?: boolean; alt?: boolean; ctrl?: boolean; small?: boolean }) {
    // Read after the first paint: the server cannot know the device, and reading it while rendering
    // made the server's page and the browser's first render disagree on a Mac.
    const [mac, setMac] = useState(false);
    useEffect(() => setMac(/Mac|iPhone|iPad/.test(navigator.platform)), []);
    const keys = [meta ? (mac ? '⌘' : 'Ctrl') : null, ctrl ? 'Ctrl' : null, alt ? (mac ? '⌥' : 'Alt') : null, shift ? '⇧' : null, children]
        .filter((key) => key !== null && key !== undefined);

    return (
        <kbd
            className={cx(
                'inline-flex items-center gap-0.5 rounded-[var(--tw-radius-sm)] bg-surface-sunken px-1.5 font-mono text-ink-muted shadow-[0_0_0_1px_var(--tw-line)]',
                small ? 'h-5 text-label-12' : 'h-6 text-label-13',
            )}
        >
            {keys.map((key, index) => (
                <span key={index}>{key}</span>
            ))}
        </kbd>
    );
}
