import { forwardRef, type ComponentProps, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { TextareaField } from '@/components/Fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { TableCell, TableHead } from '@/components/ui/table';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import type { SharedProps } from '@/types/page';
import { readMarks, type Run } from './marks';

/*
| What Catalog's list screens share (catalog.md §4.4): the page's language and a name kept in both,
| an item's state as words and colour, the ⋯ button, a description typed with the products file's
| marks and its preview (P1), and a photo chosen for upload (P5). Each screen is shadcn's parts under
| Geist's rules (frontend.md §1.11); nothing here is a component of its own making beyond arranging
| them.
*/

export type Locale = 'ar' | 'en';

export function useLocale(): Locale {
    return usePage<SharedProps>().props.locale;
}

/** A name kept in both languages, in the page's. */
export function nameIn(locale: Locale, ar: string | null, en: string | null): string {
    return (locale === 'ar' ? ar || en : en || ar) ?? '';
}

/** An item's state in words, never colour alone (Geist's Badge): Active green, Inactive grey. */
export function StateBadge({ active }: { active: boolean }) {
    const t = useTranslator();

    return (
        <Badge className={tone(active ? 'green-subtle' : 'gray-subtle')} data-test="state">
            {active ? t('catalog::admin.state.active') : t('catalog::admin.state.inactive')}
        </Badge>
    );
}

/** The two names' headers, the page's language first (b2b.md amendment 25). */
export function NameHeads({ locale }: { locale: Locale }) {
    const t = useTranslator();
    const ar = <TableHead key="ar">{t('catalog::admin.column.name_ar')}</TableHead>;
    const en = <TableHead key="en">{t('catalog::admin.column.name_en')}</TableHead>;

    return <>{locale === 'ar' ? [ar, en] : [en, ar]}</>;
}

/** The two names, the page's language first, each in its own direction. */
export function NameCells({ locale, ar, en, lead }: { locale: Locale; ar: string; en: string; lead?: ReactNode }) {
    const arCell = (
        <TableCell key="ar">
            <span className="flex items-center gap-2">
                {locale === 'ar' ? lead : null}
                <span dir="rtl">{ar}</span>
            </span>
        </TableCell>
    );
    const enCell = (
        <TableCell key="en">
            <span className="flex items-center gap-2">
                {locale === 'en' ? lead : null}
                <span dir="ltr">{en}</span>
            </span>
        </TableCell>
    );

    return <>{locale === 'ar' ? [arCell, enCell] : [enCell, arCell]}</>;
}

/**
 * Why a shared list's buttons are out of reach (P2): a reader holding its job in some stores reads
 * the list, and every change needs the job with All Stores. Undefined for whoever may change it.
 */
export function useAllStoresReason(mayChange: boolean): string | undefined {
    const t = useTranslator();

    return mayChange ? undefined : t('catalog::admin.all_stores');
}

/** A row's ⋯ button for a reader who may not change the list: shown, out of reach, with the reason. */
export function MoreButtonOff({ name, reason }: { name: string; reason: string }) {
    const t = useTranslator();

    return (
        <ActionButton variant="ghost" size="icon-sm" aria-label={`${t('ui.more_actions')}: ${name}`} disabledReason={reason}>
            <MoreHorizontal aria-hidden="true" />
        </ActionButton>
    );
}

/** A row's ⋯ button: its name says whose actions, and a spinner stands in while one is on its way. */
export const MoreButton = forwardRef<HTMLButtonElement, ComponentProps<typeof Button> & { name: string; busy?: boolean }>(function MoreButton(
    { name, busy = false, ...rest },
    ref,
) {
    const t = useTranslator();

    return (
        <Button
            ref={ref}
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`${t('ui.more_actions')}: ${name}`}
            aria-busy={busy || undefined}
            title={t('ui.more_actions')}
            {...rest}
        >
            {busy ? <Spinner aria-hidden="true" role={undefined} aria-label={undefined} /> : <MoreHorizontal aria-hidden="true" />}
        </Button>
    );
});

/**
 * A description or terms typed with the products file's marks (P1), and what it will look like,
 * under the box as it is typed - neither Geist nor shadcn has a text editor.
 */
export function MarksField({
    id,
    label,
    value,
    onChange,
    error,
    dir,
    rows = 6,
    disabled = false,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    dir: 'rtl' | 'ltr';
    rows?: number;
    disabled?: boolean;
}) {
    const t = useTranslator();
    const blocks = readMarks(value);

    return (
        <div className="grid gap-2">
            <TextareaField
                id={id}
                dir={dir}
                rows={rows}
                label={label}
                helper={t('catalog::admin.marks.helper')}
                value={value}
                error={error}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                data-test={id}
            />
            {blocks.length === 0 ? null : (
                <div dir={dir} className="material-small grid gap-2 bg-surface-sunken p-3 text-copy-14 text-ink" aria-label={t('catalog::admin.marks.preview')} data-test={`${id}-preview`}>
                    {blocks.map((block, index) =>
                        block.type === 'heading' ? (
                            <p key={index} className="text-heading-16">
                                <Runs runs={block.runs} />
                            </p>
                        ) : block.type === 'list' ? (
                            <ul key={index} className="list-disc ps-5">
                                {block.items.map((item, at) => (
                                    <li key={at}>
                                        <Runs runs={item} />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p key={index}>
                                <Runs runs={block.runs} />
                            </p>
                        ),
                    )}
                </div>
            )}
        </div>
    );
}

function Runs({ runs }: { runs: Run[] }) {
    return (
        <>
            {runs.map((run, index) => (run.bold ? <strong key={index}>{run.text}</strong> : <span key={index}>{run.text}</span>))}
        </>
    );
}

/**
 * A photo chosen for upload (P5): the one it has, a file to replace it, or Remove. Platform checks
 * the file's type and size; a refusal comes back under the field.
 */
export function ImageField({
    id,
    label,
    held,
    current,
    onFile,
    remove,
    onRemove,
    error,
}: {
    id: string;
    label: string;
    /** Whether it has a photo now - which may not have its thumbnail yet, its sizes still being made. */
    held: boolean;
    /** The photo it has now, as a thumbnail's address, once its sizes are ready. */
    current: string | null;
    onFile: (file: File | null) => void;
    remove: boolean;
    onRemove: (remove: boolean) => void;
    error?: string;
}) {
    const t = useTranslator();

    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex items-center gap-3">
                {current !== null && !remove ? <img src={current} alt="" className="size-12 rounded-md border border-line object-contain" /> : null}
                <Input
                    id={id}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    onChange={(event) => onFile(event.target.files?.[0] ?? null)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={`${id}-helper${error ? ` ${id}-error` : ''}`}
                    data-test={id}
                />
            </div>
            <FieldDescription id={`${id}-helper`}>{t('catalog::admin.image.helper')}</FieldDescription>
            {held ? (
                <div className="flex items-center gap-2">
                    <Checkbox id={`${id}-remove`} checked={remove} onCheckedChange={(on) => onRemove(on === true)} data-test={`${id}-remove`} />
                    <Label htmlFor={`${id}-remove`} className="text-copy-14">
                        {t('catalog::admin.image.remove')}
                    </Label>
                </div>
            ) : null}
            {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
        </Field>
    );
}
