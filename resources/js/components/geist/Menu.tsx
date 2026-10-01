import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { DropdownMenu as MenuPrimitive } from 'radix-ui';
import { cx } from './cx';
import { Tooltip } from './Tooltip';

/*
| Geist's Menu (frontend.md 1.10): actions on one thing, behind a visible trigger.
|
| Opens on click, never hover; flips to stay on screen; closes on a choice, Escape or a click
| outside, and gives focus back to the trigger. Items are Title Case Verb + Noun ("Rename Role"),
| end in "…" only when they open a dialog, and destructive ones sit last, after a divider. About ten
| items at most - past that, sections. A locked item shows a lock and says why.
*/

type MenuProps = {
    /** The visible trigger: a Button, usually `tertiary` and `svgOnly`. */
    trigger: ReactNode;
    children: ReactNode;
    align?: 'start' | 'end';
};

export function Menu({ trigger, children, align = 'end' }: MenuProps) {
    return (
        <MenuPrimitive.Root modal={false}>
            <MenuPrimitive.Trigger asChild>{trigger}</MenuPrimitive.Trigger>
            <MenuPrimitive.Portal>
                <MenuPrimitive.Content
                    align={align}
                    sideOffset={6}
                    collisionPadding={8}
                    className="material-menu z-50 min-w-48 p-1.5 text-label-14 text-ink"
                >
                    {children}
                </MenuPrimitive.Content>
            </MenuPrimitive.Portal>
        </MenuPrimitive.Root>
    );
}

const ITEM =
    'flex h-9 cursor-pointer select-none items-center gap-2 rounded-[var(--tw-radius-sm)] px-2 outline-none data-[highlighted]:bg-surface-sunken [&_svg]:size-4 [&_svg]:shrink-0';

type ItemProps = {
    children: ReactNode;
    onSelect?: () => void;
    href?: string;
    /** A destructive item: red, and placed last after a MenuDivider. */
    type?: 'default' | 'error';
    prefix?: ReactNode;
    /** Why the person may not do this here: the item shows a lock and stays inert. */
    lockedReason?: string;
    'data-test'?: string;
};

export function MenuItem({ children, onSelect, href, type = 'default', prefix, lockedReason, ...rest }: ItemProps) {
    if (lockedReason !== undefined) {
        return (
            <Tooltip text={lockedReason} side="left">
                <MenuPrimitive.Item {...rest} disabled className={cx(ITEM, 'cursor-not-allowed text-ink-subtle')}>
                    <Lock aria-hidden="true" />
                    {children}
                </MenuPrimitive.Item>
            </Tooltip>
        );
    }

    const look = cx(ITEM, type === 'error' ? 'text-bad' : 'text-ink');

    if (href !== undefined) {
        return (
            <MenuPrimitive.Item {...rest} asChild className={look}>
                <Link href={href}>
                    {prefix}
                    {children}
                </Link>
            </MenuPrimitive.Item>
        );
    }

    return (
        <MenuPrimitive.Item {...rest} onSelect={onSelect} className={look}>
            {prefix}
            {children}
        </MenuPrimitive.Item>
    );
}

export function MenuSection({ title, children }: { title: ReactNode; children: ReactNode }) {
    return (
        <MenuPrimitive.Group>
            <MenuPrimitive.Label className="px-2 pb-1 pt-2 text-label-12 font-medium text-ink-muted">{title}</MenuPrimitive.Label>
            {children}
        </MenuPrimitive.Group>
    );
}

export function MenuDivider() {
    return <MenuPrimitive.Separator className="-mx-1.5 my-1.5 h-px bg-line" />;
}
