import { useState, type ReactNode } from 'react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslator } from '@/lib/t';
import { tone, type Tone } from '@/lib/tones';
import type { ImportProblemData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';

/*
| What the import's pages share (catalog.md §4.4 S10, S11): an import's state and a product's as
| badges - in words, never colour alone -, the files' guide, a refused file's problems in the format's
| own words, a choice of rows, and Geist's Destructive Action Modal, the file's name typed (P10).
*/

/** At most this many problems come back with a refused file; past it, the page says so. */
export const PROBLEMS_SHOWN = 500;

const IMPORT_TONE: Record<string, Tone> = { DECIDING: 'blue-subtle', BRINGING_IN: 'amber-subtle', IN: 'green-subtle', FAILED: 'red-subtle' };

const PRODUCT_TONE: Record<string, Tone> = {
    WAITING: 'gray-subtle',
    REFUSED: 'red-subtle',
    IN: 'blue-subtle',
    UPDATED: 'blue-subtle',
    REPLACED: 'blue-subtle',
    SKIPPED: 'gray-subtle',
    HELD: 'amber-subtle',
    ACCEPTED: 'green-subtle',
    ARCHIVED: 'gray-subtle',
    DELETED: 'gray-subtle',
};

export function ImportStateBadge({ state }: { state: string }) {
    const t = useTranslator();

    return (
        <Badge className={tone(IMPORT_TONE[state] ?? 'gray-subtle')} data-test="import-state">
            {t(`catalog::admin_imports.state.${state}`)}
        </Badge>
    );
}

export function ProductStateBadge({ state }: { state: string }) {
    const t = useTranslator();

    return (
        <Badge className={tone(PRODUCT_TONE[state] ?? 'gray-subtle')} data-test="product-state">
            {t(`catalog::admin_imports.products.state.${state}`)}
        </Badge>
    );
}

/** The files' guide, opened beside the page so a dialog in it stays as it is. */
export function GuideLink() {
    const t = useTranslator();

    return (
        <a href="/admin/import-guide" target="_blank" rel="noopener" className="justify-self-start text-label-14 text-ink underline underline-offset-4" data-test="import-guide">
            {t('catalog::admin_imports.guide')}
        </a>
    );
}

/** A refused file's problems: where, and what - the format's own words, English as the format is. */
export function ProblemList({ problems, total }: { problems: ImportProblemData[]; total: number | null }) {
    const t = useTranslator();

    return (
        <div className="grid gap-2" data-test="file-problems">
            <p className="text-label-14 text-ink">{t('catalog::admin_store_files.problems_title')}</p>
            <ol className="grid list-decimal gap-1 ps-5 text-copy-13 text-ink" dir="ltr">
                {problems.map((problem, index) => (
                    <li key={`${index}-${problem.at}`}>
                        <span className="font-mono">{problem.at}</span>: {problem.problem}
                    </li>
                ))}
            </ol>
            {(total ?? 0) > PROBLEMS_SHOWN ? <p className="text-copy-13 text-ink-muted">{t('catalog::admin_store_files.problems_more')}</p> : null}
        </div>
    );
}

/** The rows ticked, kept to those still offered: a row gone since leaves the choice. */
export function useSelection(offered: string[]) {
    const [ticked, setTicked] = useState<string[]>([]);
    const chosen = ticked.filter((id) => offered.includes(id));

    return {
        chosen,
        has: (id: string) => chosen.includes(id),
        set: (id: string, on: boolean) => setTicked((before) => (on ? [...before.filter((other) => other !== id), id] : before.filter((other) => other !== id))),
        all: (on: boolean) => setTicked(on ? offered : []),
        every: offered.length > 0 && chosen.length === offered.length,
        clear: () => setTicked([]),
    };
}

export function SelectAll({ selection, offered, disabled }: { selection: ReturnType<typeof useSelection>; offered: number; disabled?: boolean }) {
    const t = useTranslator();

    return (
        <Checkbox
            checked={selection.every}
            disabled={disabled || offered === 0}
            onCheckedChange={(on) => selection.all(on === true)}
            aria-label={t('catalog::admin_imports.select_all')}
            data-test="select-all"
        />
    );
}

/**
 * Geist's Destructive Action Modal (P10): the file's name typed before the button acts - discarding an
 * import, deleting the products it brought in.
 */
export function ConfirmByName({
    fileName,
    title,
    body,
    confirm,
    busy,
    onConfirm,
    onOpenChange,
    returnFocusTo,
    testId,
    children,
}: {
    fileName: string;
    title: string;
    body: string;
    confirm: string;
    busy: boolean;
    onConfirm: () => void;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: React.RefObject<HTMLElement | null>;
    testId: string;
    children?: ReactNode;
}) {
    const t = useTranslator();
    const [typed, setTyped] = useState('');
    const matches = typed.trim() === fileName;

    return (
        <PanelDialog
            destructive
            open
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={body}
            busy={busy}
            confirm={
                <ActionButton variant="destructive" loading={busy} disabledReason={matches ? undefined : t('catalog::admin_imports.confirm_mismatch')} onClick={onConfirm} data-test={testId}>
                    {confirm}
                </ActionButton>
            }
        >
            <div className="grid gap-3">
                {children}
                <TextField id="confirm-name" dir="ltr" label={t('catalog::admin_imports.confirm_field')} helper={fileName} value={typed} onChange={(event) => setTyped(event.target.value)} data-test="confirm-name" />
            </div>
        </PanelDialog>
    );
}
