import type { ButtonHTMLAttributes, MouseEvent, ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { cx } from './cx';
import { Spinner } from './Spinner';
import { Tooltip } from './Tooltip';

/*
| Geist's Button and ButtonLink (frontend.md 1.10).
|
| `type` is the look, as in Geist - `default` is the primary action, `secondary` the supporting one,
| `tertiary` a quiet one, `error` a destructive confirmation, `warning` a risky one - and `typeName`
| is the HTML type, so a form's submit is `typeName="submit"`. A Button changes something; a
| ButtonLink goes somewhere.
|
| The rules Geist attaches, kept here so a screen cannot forget them:
|   - `loading` keeps the button in place and focusable, says it is busy, and ignores presses -
|     never a spinner swapped in by hand.
|   - A disabled button says why: `disabledReason` becomes its tooltip, and the button stays
|     reachable by keyboard so the reason can be read (aria-disabled rather than disabled).
|   - An icon-only button is `svgOnly` and must carry an aria-label naming the action and its
|     target; the type demands it.
|   - The label is Title Case and names what happens (writing rules, 1.10) - the words are the
|     screen's, so that rule lives in the words check, not here.
*/

export type ButtonType = 'default' | 'secondary' | 'tertiary' | 'error' | 'warning';
export type ButtonSize = 'small' | 'medium' | 'large';

type Common = {
    type?: ButtonType;
    size?: ButtonSize;
    shape?: 'square' | 'circle' | 'rounded';
    prefix?: ReactNode;
    suffix?: ReactNode;
    loading?: boolean;
    /** Why the button can't be used right now. Setting it disables the button. */
    disabledReason?: string;
    className?: string;
    children?: ReactNode;
};

type LabelRule = { svgOnly?: false; 'aria-label'?: undefined } | { svgOnly: true; 'aria-label': string };

const LOOK: Record<ButtonType, string> = {
    default: 'bg-brand text-ink-on-brand hover:bg-brand-strong',
    secondary: 'bg-surface text-ink shadow-[0_0_0_1px_var(--tw-line-strong)] hover:bg-surface-sunken',
    tertiary: 'bg-transparent text-ink hover:bg-surface-sunken',
    error: 'bg-bad text-ink-on-brand hover:opacity-90',
    warning: 'bg-warn text-ink-on-brand hover:opacity-90',
};

const SIZE: Record<ButtonSize, { box: string; square: string; text: string }> = {
    small: { box: 'h-8 px-2', square: 'size-8', text: 'text-button-14' },
    medium: { box: 'h-9 px-2.5', square: 'size-9', text: 'text-button-14' },
    large: { box: 'h-10 px-3.5', square: 'size-10', text: 'text-button-16' },
};

const UNAVAILABLE = 'cursor-not-allowed bg-surface-sunken text-ink-subtle shadow-[0_0_0_1px_var(--tw-line)] hover:bg-surface-sunken';

function classes(common: Common, svgOnly: boolean, unavailable: boolean): string {
    const { type = 'default', size = 'medium', shape, className } = common;
    const s = SIZE[size];

    return cx(
        'relative inline-flex shrink-0 select-none items-center justify-center gap-1.5 whitespace-nowrap transition-[background-color,box-shadow,opacity] duration-150 [&_svg]:shrink-0',
        s.text,
        svgOnly || shape === 'square' || shape === 'circle' ? cx(s.square, 'p-0') : s.box,
        shape === 'circle' || shape === 'rounded' ? 'rounded-full' : 'rounded-[var(--tw-radius)]',
        unavailable ? UNAVAILABLE : LOOK[type],
        className,
    );
}

function inner(common: Common): ReactNode {
    return (
        <>
            {common.loading ? <Spinner size={common.size === 'large' ? 18 : 16} /> : common.prefix}
            {common.children}
            {common.loading ? null : common.suffix}
        </>
    );
}

type ButtonProps = Common &
    LabelRule &
    Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'type' | 'prefix' | 'children' | 'className' | 'disabled'> & {
        typeName?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
    };

export function Button(props: ButtonProps) {
    const {
        type: _look,
        size: _size,
        shape: _shape,
        prefix: _prefix,
        suffix: _suffix,
        loading = false,
        disabledReason,
        className: _className,
        children: _children,
        svgOnly = false,
        typeName = 'button',
        disabled = false,
        onClick,
        ...rest
    } = props;

    const unavailable = disabled || disabledReason !== undefined;
    const inert = unavailable || loading;

    const button = (
        <button
            {...rest}
            type={typeName}
            aria-disabled={inert || undefined}
            aria-busy={loading || undefined}
            data-loading={loading || undefined}
            className={classes(props, svgOnly, unavailable)}
            onClick={(event: MouseEvent<HTMLButtonElement>) => {
                if (inert) {
                    // Still focusable, so the busy state and the reason can be read; never acting.
                    event.preventDefault();

                    return;
                }
                onClick?.(event);
            }}
        >
            {inner(props)}
        </button>
    );

    // Always inside its tooltip, shut while there is no reason: a button that gains or loses one
    // (Send, while a save runs) keeps its place in the tree and the keyboard focus on it.
    return <Tooltip text={disabledReason}>{button}</Tooltip>;
}

type ButtonLinkProps = Common &
    LabelRule & {
        href: string;
        /** A plain link for downloads and other pages outside the single-page app. */
        external?: boolean;
        'data-test'?: string;
    };

export function ButtonLink(props: ButtonLinkProps) {
    const { href, external = false, svgOnly = false, disabledReason } = props;
    const unavailable = disabledReason !== undefined;
    const common = {
        className: classes(props, svgOnly, unavailable),
        'aria-label': props['aria-label'],
        'data-test': props['data-test'],
    };

    if (unavailable) {
        // A link that cannot be followed is not a link: a reachable, explained, inert span.
        return (
            <Tooltip text={disabledReason}>
                <span {...common} role="link" aria-disabled="true" tabIndex={0}>
                    {inner(props)}
                </span>
            </Tooltip>
        );
    }

    return external ? (
        <a {...common} href={href}>
            {inner(props)}
        </a>
    ) : (
        <Link {...common} href={href}>
            {inner(props)}
        </Link>
    );
}
