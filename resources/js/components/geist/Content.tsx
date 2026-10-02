import { useState, type FormEvent, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, CircleAlert } from 'lucide-react';
import { Collapsible as CollapsiblePrimitive } from 'radix-ui';
import { useTranslator } from '@/lib/t';
import { cx } from './cx';
import { Tooltip } from './Tooltip';

/*
| Geist's blocks for what a page holds (frontend.md 1.10): Empty State, Error, Description, Entity,
| Fieldset, Collapse, Skeleton and Breadcrumbs.
|
| Empty State: a list with nothing in it - a Title Case title ("No Failed Jobs"), one sentence that
|   adds something, at most one primary action (two when there are honestly two paths).
| Error ("ErrorBlock"): a section that failed to load. Says what happened and what to do, in that
|   order; "Couldn't Load …", never "Something went wrong"; the request id under a <details>.
| Description: key/value facts on a detail page, as a definition list.
| Entity: one row of description plus one or two controls.
| Fieldset: a settings section - title, a sentence, the fields, and a footer holding what saving
|   means and the one button that saves it.
| Collapse: optional content most people skip; the heading names the topic, not the action.
| Skeleton: the shape of what is loading, the size of what will arrive.
*/

type EmptyStateProps = {
    title: ReactNode;
    description?: ReactNode;
    icon?: ReactNode;
    actions?: ReactNode;
    'data-test'?: string;
};

export function EmptyState({ title, description, icon, actions, ...rest }: EmptyStateProps) {
    return (
        <div {...rest} aria-live="polite" className="material-base flex flex-col items-center gap-3 px-6 py-12 text-center">
            {icon === undefined ? null : (
                <span aria-hidden="true" className="flex size-10 items-center justify-center rounded-full bg-surface-sunken text-ink-muted [&_svg]:size-5">
                    {icon}
                </span>
            )}
            <h2 className="text-heading-16 text-ink">{title}</h2>
            {description === undefined ? null : <p className="max-w-md text-copy-14 text-ink-muted">{description}</p>}
            {actions === undefined ? null : <div className="mt-1 flex flex-wrap justify-center gap-2">{actions}</div>}
        </div>
    );
}

type ErrorBlockProps = {
    /** "Couldn't Load Companies". */
    title: ReactNode;
    children?: ReactNode;
    /** A stable identifier to quote to support. */
    requestId?: string;
    action?: ReactNode;
};

export function ErrorBlock({ title, children, requestId, action }: ErrorBlockProps) {
    const t = useTranslator();

    return (
        <div aria-live="polite" className="material-base grid gap-2 px-5 py-4">
            <div className="flex items-center gap-2 text-bad">
                <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
                <h2 className="text-heading-14">{title}</h2>
            </div>
            {children === undefined ? null : <div className="text-copy-14 text-ink-muted">{children}</div>}
            {requestId === undefined ? null : (
                <details className="text-copy-13 text-ink-muted">
                    <summary className="cursor-pointer">{t('ui.request_id')}</summary>
                    <code className="text-copy-13-mono select-all" dir="ltr">
                        {requestId}
                    </code>
                </details>
            )}
            {action === undefined ? null : <div className="mt-1">{action}</div>}
        </div>
    );
}

export type DescriptionItem = { title: ReactNode; content: ReactNode; tooltip?: string; 'data-test'?: string };

export function Description({ items, columns = 2 }: { items: DescriptionItem[]; columns?: 1 | 2 | 3 }) {
    return (
        <dl className={cx('grid gap-x-6 gap-y-4', columns === 1 ? 'grid-cols-1' : columns === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3')}>
            {items.map((item, index) => (
                <div key={index} className="grid content-start gap-1" data-test={item['data-test']}>
                    <dt className="flex items-center gap-1 text-label-13 text-ink-muted">
                        {item.title}
                        {item.tooltip === undefined ? null : (
                            <Tooltip text={item.tooltip}>
                                <button type="button" aria-label={item.tooltip} className="text-ink-subtle">
                                    <CircleAlert aria-hidden="true" className="size-3.5" />
                                </button>
                            </Tooltip>
                        )}
                    </dt>
                    <dd className="text-label-14 text-ink">
                        {item.content === null || item.content === undefined || item.content === '' ? <span className="text-ink-subtle">—</span> : item.content}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

type EntityProps = {
    leading?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    /** One or two controls; more go behind a Menu. */
    actions?: ReactNode;
    'data-test'?: string;
};

export function Entity({ leading, title, description, actions, ...rest }: EntityProps) {
    return (
        <div {...rest} className="flex items-center gap-3 px-4 py-3">
            {leading === undefined ? null : <div className="shrink-0">{leading}</div>}
            <div className="grid min-w-0 flex-1 gap-0.5">
                <div className="truncate text-label-14 font-medium text-ink">{title}</div>
                {description === undefined ? null : <div className="truncate text-copy-13 text-ink-muted">{description}</div>}
            </div>
            {actions === undefined ? null : <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}

/** A list of Entity rows inside one surface. */
export function EntityList({ children }: { children: ReactNode }) {
    return <div className="material-base divide-y divide-line">{children}</div>;
}

type FieldsetProps = {
    title: ReactNode;
    subtitle?: ReactNode;
    children: ReactNode;
    /** What happens on save, or the state; sentence case. */
    footerStatus?: ReactNode;
    /** The one button that saves this section. */
    footerAction?: ReactNode;
    'data-test'?: string;
    as?: 'section' | 'form';
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
};

export function Fieldset({ title, subtitle, children, footerStatus, footerAction, as = 'section', onSubmit, ...rest }: FieldsetProps) {
    const body = (
        <>
            <div className="grid gap-4 p-5 sm:p-6">
                <div className="grid gap-1">
                    <h2 className="text-heading-20 text-ink">{title}</h2>
                    {subtitle === undefined ? null : <p className="text-copy-14 text-ink-muted">{subtitle}</p>}
                </div>
                {children}
            </div>
            {footerStatus === undefined && footerAction === undefined ? null : (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface-sunken px-5 py-3 sm:px-6">
                    <div className="text-copy-13 text-ink-muted">{footerStatus}</div>
                    {footerAction}
                </div>
            )}
        </>
    );

    return as === 'form' ? (
        <form {...rest} onSubmit={onSubmit} className="material-base overflow-hidden">
            {body}
        </form>
    ) : (
        <section {...rest} className="material-base overflow-hidden">
            {body}
        </section>
    );
}

type CollapseProps = {
    /** Names the topic ("Advanced Settings"), not the action. */
    title: ReactNode;
    children: ReactNode;
    defaultOpen?: boolean;
};

export function Collapse({ title, children, defaultOpen = false }: CollapseProps) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <CollapsiblePrimitive.Root open={open} onOpenChange={setOpen} className="border-b border-line">
            <CollapsiblePrimitive.Trigger className="flex w-full items-center justify-between gap-3 py-3 text-start text-heading-14 text-ink">
                {title}
                <ChevronDown aria-hidden="true" className={cx('size-4 text-ink-muted transition-transform', open && 'rotate-180')} />
            </CollapsiblePrimitive.Trigger>
            {/* Kept in the page while closed, so find-in-page still reaches it. */}
            <CollapsiblePrimitive.Content forceMount hidden={!open} className="pb-4 text-copy-14 text-ink">
                {children}
            </CollapsiblePrimitive.Content>
        </CollapsiblePrimitive.Root>
    );
}

export function Skeleton({ width, height = 16, shape = 'rounded' }: { width?: number | string; height?: number | string; shape?: 'pill' | 'rounded' | 'squared' }) {
    return (
        <span
            aria-hidden="true"
            className={cx(
                'block animate-pulse bg-surface-sunken motion-reduce:animate-none',
                shape === 'pill' ? 'rounded-full' : shape === 'rounded' ? 'rounded-[var(--tw-radius-sm)]' : 'rounded-none',
            )}
            style={{ width: width ?? '100%', height }}
        />
    );
}

export type Crumb = { label: ReactNode; href?: string };

export function Breadcrumbs({ items }: { items: Crumb[] }) {
    const t = useTranslator();

    return (
        <nav aria-label={t('ui.breadcrumbs')}>
            <ol className="flex flex-wrap items-center gap-1.5 text-label-13 text-ink-muted">
                {items.map((item, index) => {
                    const last = index === items.length - 1;

                    return (
                        <li key={index} className="inline-flex items-center gap-1.5">
                            {item.href === undefined || last ? (
                                <span aria-current={last ? 'page' : undefined} className={last ? 'text-ink' : undefined}>
                                    {item.label}
                                </span>
                            ) : (
                                <Link href={item.href} className="hover:text-ink">
                                    {item.label}
                                </Link>
                            )}
                            {last ? null : <ChevronRight aria-hidden="true" className="size-3.5 rtl:rotate-180" />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
