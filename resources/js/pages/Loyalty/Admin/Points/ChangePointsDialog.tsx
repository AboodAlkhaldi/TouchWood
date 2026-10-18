import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField, TextareaField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { figure } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { SWITCH_ITEM, SWITCH_TRACK } from '@/lib/view-switch';
import { useLocale } from '@/pages/Loyalty/Admin/Points/parts';

/*
| L3 - the change made by hand (loyalty.md §4.1; answer 8): one dialog, Add or Remove, the points, a
| reason, and the balance before and after as it is typed. No second "are you sure": the reason is
| the deliberate step. Remove is not red - a correction, not a deletion (§9.5 P4) - and is out of
| reach while there are no points to remove.
|
| The boxes keep the server's own rules (Inputs): whole points 1 to `mostByHand`, Remove at most the
| balance; a reason of 1 to 500 characters, counted after trimming. A refusal - the balance moved
| meanwhile, say - is said inside the dialog, which stays open with the boxes as typed.
*/

const REASON_MAX = 500;

type Mode = 'add' | 'remove';

export function ChangePointsDialog({
    open,
    onOpenChange,
    customerId,
    storeCode,
    storeName,
    balance,
    mostByHand,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customerId: string;
    storeCode: string;
    storeName: string;
    balance: number;
    mostByHand: number;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const form = useForm<{ mode: Mode; points: string; reason: string; store: string }>({ mode: 'add', points: '', reason: '', store: storeCode });
    const removing = form.data.mode === 'remove';
    const most = removing ? Math.min(balance, mostByHand) : mostByHand;
    const checks = useChecks(
        [
            { id: 'points', label: t('loyalty::admin_points.dialog.points'), value: form.data.points, rules: { required: true, number: { decimals: 0, min: 1, max: Math.max(most, 1) } } },
            { id: 'reason', label: t('loyalty::admin_points.dialog.reason'), value: form.data.reason, rules: { required: true, length: { max: REASON_MAX } } },
        ],
        open,
    );
    const typed = /^\d+$/.test(form.data.points.trim()) ? Number(form.data.points.trim()) : null;
    const after = typed === null ? null : removing ? balance - typed : balance + typed;
    // Remove past the balance is said before Save, as the box's own rule says it.
    const overBalance = removing && typed !== null && typed > balance;
    const nothing = removing && balance === 0;

    function close(next: boolean) {
        onOpenChange(next);

        if (!next) {
            form.reset();
            form.clearErrors();
        }
    }

    function send() {
        if (nothing || overBalance) {
            return;
        }

        checks.submit(() =>
            form.post(`/admin/points/customers/${customerId}/change`, {
                preserveScroll: true,
                onSuccess: () => close(false),
            }),
        );
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={close}
            title={t('loyalty::admin_points.dialog.title')}
            description={t('loyalty::admin_points.dialog.description', { store: storeName })}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={nothing ? t('loyalty::admin_points.dialog.nothing_to_remove') : overBalance ? t('loyalty::admin_points.dialog.at_most', { balance: figure(locale, balance) }) : checks.reason}
                    onClick={send}
                    data-test="confirm-change"
                >
                    {removing ? t('loyalty::admin_points.dialog.remove_points') : t('loyalty::admin_points.dialog.add_points')}
                </ActionButton>
            }
        >
            <form
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    send();
                }}
            >
                <ToggleGroup
                    type="single"
                    value={form.data.mode}
                    // A pressed item pressed again would clear it; the change is always one of the two.
                    onValueChange={(next) => (next === 'add' || next === 'remove' ? form.setData('mode', next) : undefined)}
                    aria-label={t('loyalty::admin_points.dialog.mode')}
                    className={SWITCH_TRACK}
                    data-test="mode"
                >
                    <ToggleGroupItem value="add" className={SWITCH_ITEM} data-test="mode-add">
                        {t('loyalty::admin_points.dialog.add')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="remove" className={SWITCH_ITEM} data-test="mode-remove">
                        {t('loyalty::admin_points.dialog.remove')}
                    </ToggleGroupItem>
                </ToggleGroup>

                <TextField
                    id="points"
                    label={t('loyalty::admin_points.dialog.points')}
                    helper={removing ? t('loyalty::admin_points.dialog.at_most', { balance: figure(locale, balance) }) : undefined}
                    check={checks.box('points', form.errors.points)}
                    inputMode="numeric"
                    dir="ltr"
                    autoFocus
                    required
                    value={form.data.points}
                    onChange={(event) => form.setData('points', event.target.value)}
                />

                <TextareaField
                    id="reason"
                    label={t('loyalty::admin_points.dialog.reason')}
                    helper={
                        <>
                            {t('loyalty::admin_points.dialog.reason_hint')}{' '}
                            <bdi dir="ltr" className="tw-figure">{`${figure(locale, form.data.reason.trim().length)} / ${figure(locale, REASON_MAX)}`}</bdi>
                        </>
                    }
                    check={checks.box('reason', form.errors.reason)}
                    required
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />

                <p className="text-copy-14 text-ink-muted" aria-live="polite" data-test="balance-line">
                    {t('loyalty::admin_points.dialog.balance_line', {
                        before: figure(locale, balance),
                        after: after === null || after < 0 ? '—' : figure(locale, after),
                    })}
                </p>

                {/* Enter in a box sends, as the main button would. */}
                <button type="submit" className="hidden" tabIndex={-1} aria-hidden="true" />
            </form>
        </PanelDialog>
    );
}
