import { type RefObject, useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { TextareaField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { PanelDialog } from '@/components/PanelDialog';
import { useLocale } from '@/components/promotions/parts';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { ReasonData, ReasonsPage } from '@/types/generated/Modules/Feedback/Presentation/Http/Resource';
import { MoreButton } from '@/pages/Catalog/Admin/parts';
import { MoveItems, SortableList, useSavedOrder } from '@/pages/Catalog/Admin/SortableList';

/*
| Support › Rejection Reasons (feedback.md §4.1 F9, §4.2 B4): the ready reasons a moderator may pick when
| rejecting or unpublishing, each in Arabic and English, the same in every store. Their order is the order
| offered: dragged, or one moved to the top or the bottom from its ⋯ menu (the owner's ordering rule); the
| server takes every reason once, so each save sends the whole order. Only an admin of all stores edits
| them; anyone else who decides reviews or questions sees the list read-only.
*/

const W = 'feedback::admin_reasons';

type Dialog = { action: 'add' | 'edit' | 'delete'; reason: ReasonData | null } | null;

export default function Reasons({ reasons, mayEdit }: ReasonsPage) {
    const t = useTranslator();
    const locale = useLocale();
    const [dialog, setDialog] = useState<Dialog>(null);
    const opener = useRef<HTMLElement | null>(null);
    const close = (open: boolean) => (open ? undefined : setDialog(null));
    const { order, move } = useSavedOrder(
        reasons.map((reason) => reason.id),
        '/admin/rejection-reasons/order',
    );
    const byId = new Map(reasons.map((reason) => [reason.id, reason]));
    const textOf = (reason: ReasonData) => (locale === 'en' ? reason.textEn : reason.textAr);

    return (
        <AdminLayout
            title={t(`${W}.title`)}
            subtitle={t(`${W}.subtitle`)}
            action={
                mayEdit ? (
                    <ActionButton type="button" onClick={() => setDialog({ action: 'add', reason: null })} data-test="add-reason">
                        {t(`${W}.add`)}
                    </ActionButton>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <FormError />
                {mayEdit ? null : (
                    <p className="text-copy-13 text-ink-muted" data-test="reasons-read-only">
                        {t(`${W}.read_only`)}
                    </p>
                )}
                {reasons.length === 0 ? (
                    <Empty className="material-base" data-test="reasons-empty">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t(`${W}.empty`)}</EmptyTitle>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <SortableList
                        testPrefix="reason"
                        disabled={!mayEdit}
                        onChange={move}
                        items={order.flatMap((id, index) => {
                            const reason = byId.get(id);

                            if (reason === undefined) {
                                return [];
                            }

                            return [
                                {
                                    id,
                                    label: textOf(reason),
                                    content: (
                                        <span className="grid gap-0.5" data-test={`reason-${id}`}>
                                            <span className="text-copy-14 text-ink">{textOf(reason)}</span>
                                            <span className="text-copy-13 text-ink-muted" dir={locale === 'en' ? 'rtl' : 'ltr'}>
                                                {locale === 'en' ? reason.textAr : reason.textEn}
                                            </span>
                                        </span>
                                    ),
                                    extra: mayEdit ? (
                                        <ReasonMenu
                                            name={textOf(reason)}
                                            first={index === 0}
                                            last={index === order.length - 1}
                                            toTop={() => move([id, ...order.filter((each) => each !== id)])}
                                            toBottom={() => move([...order.filter((each) => each !== id), id])}
                                            open={(action, trigger) => {
                                                opener.current = trigger;
                                                setDialog({ action, reason });
                                            }}
                                        />
                                    ) : undefined,
                                },
                            ];
                        })}
                    />
                )}
            </div>

            {dialog !== null && dialog.action !== 'delete' ? <ReasonDialog reason={dialog.reason} open onOpenChange={close} returnFocusTo={opener} /> : null}
            {dialog !== null && dialog.action === 'delete' && dialog.reason !== null ? <DeleteReasonDialog reason={dialog.reason} open onOpenChange={close} returnFocusTo={opener} /> : null}
        </AdminLayout>
    );
}

function ReasonMenu({
    name,
    first,
    last,
    toTop,
    toBottom,
    open,
}: {
    name: string;
    first: boolean;
    last: boolean;
    toTop: () => void;
    toBottom: () => void;
    open: (action: 'edit' | 'delete', trigger: HTMLElement | null) => void;
}) {
    const t = useTranslator();
    const more = useRef<HTMLButtonElement>(null);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <MoreButton ref={more} name={name} data-test="reason-actions" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-56">
                <DropdownMenuItem onSelect={() => open('edit', more.current)} data-test="edit-reason">
                    {t(`${W}.edit`)}
                </DropdownMenuItem>
                <MoveItems first={first} last={last} onTop={toTop} onBottom={toBottom} />
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => open('delete', more.current)} data-test="delete-reason">
                    {t(`${W}.delete`)}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ReasonDialog({ reason, open, onOpenChange, returnFocusTo }: { reason: ReasonData | null; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const initial = () => ({ text_ar: reason?.textAr ?? '', text_en: reason?.textEn ?? '' });
    const form = useForm(initial());

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, reason?.id]);

    // Each box as typed (frontend.md §1.7), with the domain's rules: both languages, up to 500 characters.
    const text = { required: true, length: { max: 500 } };
    const checks = useChecks([
        { id: 'reason-text-ar', label: t(`${W}.text_ar`), value: form.data.text_ar, rules: text },
        { id: 'reason-text-en', label: t(`${W}.text_en`), value: form.data.text_en, rules: text },
    ]);
    const title = reason === null ? t(`${W}.add_title`) : t(`${W}.edit_title`);

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t(`${W}.subtitle`)}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={checks.reason}
                    onClick={() => checks.submit(() => form.post(reason === null ? '/admin/rejection-reasons' : `/admin/rejection-reasons/${reason.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }))}
                    data-test="confirm-reason"
                >
                    {reason === null ? title : t(`${W}.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextareaField id="reason-text-ar" dir="rtl" label={t(`${W}.text_ar`)} value={form.data.text_ar} check={checks.box('reason-text-ar', form.errors.text_ar)} onChange={(event) => form.setData('text_ar', event.target.value)} data-test="reason-text-ar" />
                <TextareaField id="reason-text-en" dir="ltr" label={t(`${W}.text_en`)} value={form.data.text_en} check={checks.box('reason-text-en', form.errors.text_en)} onChange={(event) => form.setData('text_en', event.target.value)} data-test="reason-text-en" />
            </div>
        </PanelDialog>
    );
}

function DeleteReasonDialog({ reason, open, onOpenChange, returnFocusTo }: { reason: ReasonData; open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: RefObject<HTMLElement | null> }) {
    const t = useTranslator();
    const [busy, setBusy] = useState(false);

    return (
        <PanelDialog
            destructive
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={t(`${W}.delete_title`)}
            description={t(`${W}.delete_body`)}
            busy={busy}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={busy}
                    onClick={() => router.post(`/admin/rejection-reasons/${reason.id}/delete`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) })}
                    data-test="confirm-delete-reason"
                >
                    {t(`${W}.delete_title`)}
                </ActionButton>
            }
        >
            {null}
        </PanelDialog>
    );
}
