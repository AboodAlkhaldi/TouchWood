import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DecisionDialog } from '@/components/feedback/parts';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ReasonData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| Many at once (feedback.md §4.1 F1, owner's Q4): the items ticked on a page - up to 50 - approved, or
| rejected or unpublished with one optional reason. Each is decided on its own; the toast says how many
| were done and how many someone else had decided meanwhile.
*/

const W = 'feedback::admin_support';

export function useSelection() {
    const [ids, setIds] = useState<Set<string>>(new Set());

    return {
        ids,
        has: (id: string) => ids.has(id),
        toggle: (id: string) =>
            setIds((before) => {
                const next = new Set(before);
                if (next.has(id)) next.delete(id);
                else next.add(id);

                return next;
            }),
        setAll: (all: string[], on: boolean) => setIds(on ? new Set(all) : new Set()),
        clear: () => setIds(new Set()),
    };
}

/** The tick box beside one item, named after it. */
export function SelectBox({ checked, onChange, name, test }: { checked: boolean; onChange: () => void; name: string; test: string }) {
    const t = useTranslator();

    return <Checkbox checked={checked} onCheckedChange={onChange} aria-label={t(`${W}.bulk.select`, { name })} data-test={test} />;
}

/** The bar over a list with something ticked: how many, and what may be done to all of them. */
export function BulkBar({
    all,
    selection,
    locale,
    url,
    store,
    actions,
    reasons,
}: {
    all: string[];
    selection: ReturnType<typeof useSelection>;
    locale: string;
    url: string;
    store: string;
    actions: ('approve' | 'reject' | 'unpublish')[];
    reasons: ReasonData[];
}) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<'reject' | 'unpublish' | null>(null);
    const ids = [...selection.ids];
    const everything = all.length > 0 && all.every((id) => selection.has(id));

    return (
        <div className="flex flex-wrap items-center gap-3" data-test="bulk-bar">
            <label className="inline-flex items-center gap-2 text-copy-13 text-ink-muted">
                <Checkbox checked={everything} onCheckedChange={(on) => selection.setAll(all, on === true)} data-test="select-all" />
                {t(`${W}.bulk.select_all`)}
            </label>
            {ids.length === 0 ? null : (
                <>
                    <span className="text-copy-13 text-ink-muted" data-test="selected-count">
                        {t(`${W}.bulk.selected`, { count: figure(locale, ids.length) })}
                    </span>
                    {actions.includes('approve') ? (
                        <ActionButton
                            type="button"
                            size="sm"
                            loading={busy}
                            data-test="bulk-approve"
                            onClick={() => router.post(url, { store, action: 'approve', ids }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: selection.clear })}
                        >
                            {t(`${W}.bulk.approve`)}
                        </ActionButton>
                    ) : null}
                    {actions.includes('reject') ? (
                        <Button type="button" size="sm" variant="outline" onClick={() => setDialog('reject')} data-test="bulk-reject">
                            {`${t(`${W}.bulk.reject`)}…`}
                        </Button>
                    ) : null}
                    {actions.includes('unpublish') ? (
                        <Button type="button" size="sm" variant="outline" onClick={() => setDialog('unpublish')} data-test="bulk-unpublish">
                            {`${t(`${W}.bulk.unpublish`)}…`}
                        </Button>
                    ) : null}
                </>
            )}
            {dialog === null ? null : (
                <DecisionDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setDialog(null);
                            selection.clear();
                        }
                    }}
                    title={t(`${W}.bulk.${dialog}_title`)}
                    description={t(`${W}.bulk.description`)}
                    action={url}
                    field="reason"
                    confirm={t(`${W}.bulk.${dialog}_title`)}
                    destructive
                    reasons={reasons}
                    language={locale === 'en' ? 'en' : 'ar'}
                    extra={{ store, action: dialog, ids }}
                />
            )}
        </div>
    );
}
