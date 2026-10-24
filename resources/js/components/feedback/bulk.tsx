import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DecisionDialog } from '@/components/feedback/parts';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { ReasonData } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';

/*
| Many at once (feedback.md §4.1 F1, owner's Q4): the items ticked in the list - up to 50, the server's
| most - approved, or rejected or unpublished with one optional reason. Each is decided on its own; the
| toast says how many were done and how many someone else had decided meanwhile.
*/

const W = 'feedback::admin_support';

/** The most one decision takes (BulkInputs::MOST). */
export const MOST = 50;

/**
 * The ticked items among those shown: one decided on its own leaves the list, and with it the ticks; a
 * new tab starts with none.
 *
 * @param shown the list's ids, as shown now
 * @param tab what the list shows - a change clears every tick
 */
export function useSelection(shown: string[], tab: string) {
    const [ticked, setTicked] = useState<Set<string>>(new Set());
    const ids = shown.filter((id) => ticked.has(id));

    useEffect(() => setTicked(new Set()), [tab]);

    return {
        ids,
        full: ids.length >= MOST,
        has: (id: string) => ticked.has(id),
        toggle: (id: string) =>
            setTicked((before) => {
                const next = new Set(before);
                if (next.has(id)) next.delete(id);
                else next.add(id);

                return next;
            }),
        setAll: (on: boolean) => setTicked(on ? new Set(shown.slice(0, MOST)) : new Set()),
        clear: () => setTicked(new Set()),
    };
}

/** The tick box beside one item, named after it; out of reach once 50 are ticked. */
export function SelectBox({ checked, full, onChange, name, test }: { checked: boolean; full: boolean; onChange: () => void; name: string; test: string }) {
    const t = useTranslator();

    return <Checkbox checked={checked} disabled={full && !checked} onCheckedChange={onChange} aria-label={t(`${W}.bulk.select`, { name })} data-test={test} />;
}

/** The bar over a list: how many are ticked, and what may be done to all of them. */
export function BulkBar({
    shown,
    selection,
    locale,
    url,
    store,
    actions,
    reasons,
}: {
    shown: number;
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
    const ids = selection.ids;
    const everything = ids.length > 0 && ids.length === Math.min(shown, MOST);

    return (
        <div className="flex flex-wrap items-center gap-3" data-test="bulk-bar">
            <label className="inline-flex items-center gap-2 text-copy-13 text-ink-muted">
                <Checkbox checked={everything} onCheckedChange={(on) => selection.setAll(on === true)} data-test="select-all" />
                {t(shown > MOST ? `${W}.bulk.select_first` : `${W}.bulk.select_all`, { count: figure(locale, MOST) })}
            </label>
            {ids.length === 0 ? null : (
                <>
                    <span className="text-copy-13 text-ink-muted" data-test="selected-count">
                        {t(`${W}.bulk.selected`, { count: figure(locale, ids.length) })}
                        {selection.full ? ` · ${t(`${W}.bulk.most`, { count: figure(locale, MOST) })}` : ''}
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
                    onOpenChange={(open) => (open ? undefined : setDialog(null))}
                    onDone={selection.clear}
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
