import { type RefObject, useEffect, useState } from 'react';
import { useForm, type InertiaFormProps } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { DestructiveActionDialog } from '@/components/DestructiveActionDialog';
import { Messages, SelectField, TextareaField, TextField, checked, describedBy } from '@/components/Fields';
import { useFreshRefusal } from '@/components/FormError';
import { Note } from '@/components/Note';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { NativeSelectOption } from '@/components/ui/native-select';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { StaffFileData, StaffTypeChoiceData } from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { nameIn, type Locale } from '../shared';
import { PanelDialog } from '@/components/PanelDialog';

/*
| The decisions on one company (b2b.md §3.2, §4.6, amendments 21 and 23), on shadcn's Dialog and
| AlertDialog with Geist's Modal rules: the title a statement, the primary button repeating it, the
| toast answering with the same verb, and a refusal said inside the dialog - never behind it - which
| stays open to retry (Geist's Modal; the audit of B2B).
|
| Reject is destructive - shadcn's AlertDialog, focus starting on Cancel - with its button waking
| only once a reason is written; it can be undone by applying again (21(g)). Suspend asks for the
| company's name to be typed (owner, amendment 23(c)): Geist's Destructive Action Modal, because
| suspending stops all ordering and emails the customer; the reason is still required. Approve,
| Reinstate and Correct Company Type are plain Dialogs. Each closes once the server agreed.
*/

type Base = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: string;
    /** For a dialog opened from the page's ⋯ menu: its button takes focus back. */
    returnFocusTo?: RefObject<HTMLElement | null>;
};

function url(companyId: string, action: string): string {
    return `/admin/companies/${companyId}/${action}`;
}

function TypeNote({ note }: { note: string | null }) {
    const t = useTranslator();

    return note === null ? null : (
        <Note variant="warning" size="small" label={t('b2b::admin_companies.application.type_changed.label')}>
            {note}
        </Note>
    );
}

export function ApproveModal({ open, onOpenChange, companyId, typeNote }: Base & { typeNote: string | null }) {
    const t = useTranslator();
    const form = useForm({ note: '' });
    // The note as typed (frontend.md §1.7): optional, at most 1,000 characters (Remark::MAX).
    const checks = useChecks([{ id: 'approve-note', label: t('b2b::admin_companies.approve.note'), value: form.data.note, rules: { length: { max: 1000 } } }], open);

    function submit() {
        checks.submit(() =>
            form.post(url(companyId, 'approve'), {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            }),
        );
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('b2b::admin_companies.approve.title')}
            description={t('b2b::admin_companies.approve.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-approve">
                    {t('b2b::admin_companies.approve.button')}
                </ActionButton>
            }
        >
            <TypeNote note={typeNote} />
            <TextareaField
                id="approve-note"
                rows={3}
                label={t('b2b::admin_companies.approve.note')}
                helper={t('b2b::admin_companies.approve.note_helper')}
                check={checks.box('approve-note', form.errors.note)}
                value={form.data.note}
                onChange={(event) => form.setData('note', event.target.value)}
                data-test="approve-note"
            />
        </PanelDialog>
    );
}

type RejectForm = {
    reason: string;
    flags: string[];
    documents: string[];
    requests: { kind: string; label: string }[];
};

/** The five fields a rejection may mark: B2B's FlaggedField, in its order (StaffScreenFieldsTest). */
const FIELDS = ['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const;

/** Each field with its name, the keys written out so the words check reads each one. */
function useFieldNames(): { field: string; label: string }[] {
    const t = useTranslator();
    const label: Record<(typeof FIELDS)[number], string> = {
        name: t('b2b::admin_companies.application.flag.name'),
        company_type: t('b2b::admin_companies.application.flag.company_type'),
        cr_number: t('b2b::admin_companies.application.flag.cr_number'),
        tax_number: t('b2b::admin_companies.application.flag.tax_number'),
        address: t('b2b::admin_companies.application.flag.address'),
    };

    return FIELDS.map((field) => ({ field, label: label[field] }));
}

/**
 * A reason, and what the next application must fix or add (§1.2, amendment 4): any of the five
 * fields, any paper the waiting application sent, and requests for a text or a file under a label -
 * shadcn's field-checkbox and its form-array pattern, each group's helper and error tied to it.
 *
 * The company reads them word for word, so the reason and each request are written in its own
 * language - the dialog names it, and its fields take that language and direction (the owner,
 * 2026-10-06). What is written stays as written, whatever language the account picks later.
 */
export function RejectModal({
    open,
    onOpenChange,
    companyId,
    papers,
    locale,
    typeNote,
    writeIn,
}: Base & { papers: StaffFileData[]; locale: Locale; typeNote: string | null; writeIn: Locale }) {
    const t = useTranslator();
    const fields = useFieldNames();
    const form = useForm<RejectForm>({ reason: '', flags: [], documents: [], requests: [] });

    function toggle(list: 'flags' | 'documents', value: string, on: boolean) {
        const current = form.data[list];
        form.setData(list, on ? [...current, value] : current.filter((item) => item !== value));
    }

    function setRequest(index: number, change: Partial<{ kind: string; label: string }>) {
        form.setData(
            'requests',
            form.data.requests.map((each, at) => (at === index ? { ...each, ...change } : each)),
        );
    }

    // Each box as typed (frontend.md §1.7), with the domain's rules: the reason required, at most 1,000
    // characters (Remark::MAX); each request's label required, one line of at most 200
    // (ApplicationRequest::LABEL_MAX).
    const checks = useChecks([
        { id: 'reject-reason', label: t('b2b::admin_companies.reason'), value: form.data.reason, rules: { required: true, length: { max: 1000 } } },
        ...form.data.requests.map((request, index) => ({
            id: `request-label-${index}`,
            label: t('b2b::admin_companies.reject.request_label'),
            value: request.label,
            rules: { required: true, length: { max: 200 } },
        })),
    ], open);

    function submit() {
        checks.submit(() =>
            form.post(url(companyId, 'reject'), {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            }),
        );
    }

    const flagsDescribed = ['reject-flags-helper', form.errors.flags ? 'reject-flags-error' : null].filter(Boolean).join(' ');
    const requestsDescribed = ['reject-requests-helper', form.errors.requests ? 'reject-requests-error' : null].filter(Boolean).join(' ');

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            destructive
            wide
            title={t('b2b::admin_companies.reject.title')}
            description={t('b2b::admin_companies.reject.body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    variant="destructive"
                    loading={form.processing}
                    disabledReason={(form.data.reason.trim() === '' ? t('b2b::admin_companies.reason_missing') : undefined) ?? checks.reason}
                    onClick={submit}
                    data-test="confirm-reject"
                >
                    {t('b2b::admin_companies.reject.button')}
                </ActionButton>
            }
        >
            {/* The reviewer decides with the type's deactivation in front of them, rejecting as much
                as approving (§3.2). */}
            <TypeNote note={typeNote} />
            {/* Tied to the reason and each request, so a field read on its own still says it. */}
            <Note id="reject-write-in" variant="secondary" data-test="reject-write-in">
                {writeIn === 'ar' ? t('b2b::admin_companies.reject.write_in.ar') : t('b2b::admin_companies.reject.write_in.en')}
            </Note>
            <TextareaField
                id="reject-reason"
                lang={writeIn}
                dir={writeIn === 'ar' ? 'rtl' : 'ltr'}
                alsoDescribedBy="reject-write-in"
                rows={3}
                label={t('b2b::admin_companies.reason')}
                helper={t('b2b::admin_companies.reject.reason_helper')}
                check={checks.box('reject-reason', form.errors.reason)}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                data-test="reject-reason"
            />

            <FieldSet aria-describedby={flagsDescribed} className="gap-3">
                <FieldLegend variant="label" className="mb-0 text-label-14 text-ink">
                    {t('b2b::admin_companies.reject.flags')}
                </FieldLegend>
                <FieldDescription id="reject-flags-helper" className="text-copy-13 text-ink-muted">
                    {t('b2b::admin_companies.reject.flags_helper')}
                </FieldDescription>
                <FieldGroup className="gap-2">
                    {fields.map(({ field, label }) => (
                        <Field key={field} orientation="horizontal">
                            <Checkbox
                                id={`flag-${field}`}
                                checked={form.data.flags.includes(field)}
                                onCheckedChange={(on) => toggle('flags', field, on === true)}
                                className="border-ink-subtle"
                                data-test={`flag-${field}`}
                            />
                            <FieldLabel htmlFor={`flag-${field}`} className="text-label-14 font-normal text-ink">
                                {label}
                            </FieldLabel>
                        </Field>
                    ))}
                    {papers.map((paper) => (
                        <Field key={paper.documentTypeId} orientation="horizontal">
                            <Checkbox
                                id={`flag-document-${paper.documentTypeId}`}
                                checked={form.data.documents.includes(paper.documentTypeId)}
                                onCheckedChange={(on) => toggle('documents', paper.documentTypeId, on === true)}
                                className="border-ink-subtle"
                                data-test={`flag-document-${paper.documentTypeId}`}
                            />
                            <FieldLabel htmlFor={`flag-document-${paper.documentTypeId}`} className="text-label-14 font-normal text-ink">
                                {nameIn(locale, paper.documentTypeNameAr, paper.documentTypeNameEn) || t('b2b::admin_companies.application.paper')}
                            </FieldLabel>
                        </Field>
                    ))}
                </FieldGroup>
                {form.errors.flags ? <FieldError id="reject-flags-error">{form.errors.flags}</FieldError> : null}
            </FieldSet>

            <FieldSet aria-describedby={requestsDescribed} className="gap-3">
                <FieldLegend variant="label" className="mb-0 text-label-14 text-ink">
                    {t('b2b::admin_companies.reject.requests')}
                </FieldLegend>
                <FieldDescription id="reject-requests-helper" className="text-copy-13 text-ink-muted">
                    {t('b2b::admin_companies.reject.requests_helper')}
                </FieldDescription>
                {form.data.requests.map((request, index) => {
                    const id = `request-label-${index}`;
                    // Wired to its check by hand: an InputGroupInput, its remove button beside it.
                    const check = checks.box(id);

                    return (
                        <div key={index} className="grid gap-3 sm:grid-cols-[8rem_minmax(0,1fr)]" data-test={`request-${index}`}>
                            <SelectField id={`request-kind-${index}`} label={t('b2b::admin_companies.reject.request_kind')} value={request.kind} onChange={(event) => setRequest(index, { kind: event.target.value })}>
                                <NativeSelectOption value="TEXT">{t('b2b::admin_companies.application.kind.TEXT')}</NativeSelectOption>
                                <NativeSelectOption value="FILE">{t('b2b::admin_companies.application.kind.FILE')}</NativeSelectOption>
                            </SelectField>
                            {/* The row's own remove, inside its label's field (shadcn's form-array). */}
                            <Field>
                                <FieldLabel htmlFor={id}>{t('b2b::admin_companies.reject.request_label')}</FieldLabel>
                                <InputGroup>
                                    <InputGroupInput
                                        id={id}
                                        lang={writeIn}
                                        dir={writeIn === 'ar' ? 'rtl' : 'ltr'}
                                        {...checked<HTMLInputElement>(check, {})}
                                        aria-invalid={check.message ? true : undefined}
                                        aria-describedby={describedBy(id, undefined, check.message, 'reject-write-in')}
                                        placeholder={t('b2b::admin_companies.reject.request_label_placeholder')}
                                        value={request.label}
                                        onChange={(event) => {
                                            setRequest(index, { label: event.target.value });
                                            check.onType();
                                        }}
                                        data-test={id}
                                    />
                                    <InputGroupAddon align="inline-end">
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label={t('b2b::admin_companies.reject.remove_request', { number: index + 1 })}
                                            onClick={() => form.setData('requests', form.data.requests.filter((_, at) => at !== index))}
                                            data-test={`remove-request-${index}`}
                                        >
                                            <X aria-hidden="true" />
                                        </InputGroupButton>
                                    </InputGroupAddon>
                                </InputGroup>
                                <Messages id={id} error={check.message} check={check} />
                            </Field>
                        </div>
                    );
                })}
                {form.errors.requests ? <FieldError id="reject-requests-error">{form.errors.requests}</FieldError> : null}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => form.setData('requests', [...form.data.requests, { kind: 'TEXT', label: '' }])}
                        data-test="add-request"
                    >
                        <Plus aria-hidden="true" />
                        {t('b2b::admin_companies.reject.add_request')}
                    </Button>
                </div>
            </FieldSet>
        </PanelDialog>
    );
}

/**
 * Suspending from any status, with a reason the customer is emailed (§4.1) - asked with the
 * company's name typed (owner, amendment 23(c): Geist's Destructive Action Modal).
 */
export function SuspendModal({ open, onOpenChange, companyId, companyName, returnFocusTo }: Base & { companyName: string }) {
    const t = useTranslator();
    const form = useForm({ reason: '' });
    const refusal = useFreshRefusal(open);
    // The reason as typed (frontend.md §1.7): required, at most 1,000 characters (Remark::MAX).
    const checks = useChecks([{ id: 'suspend-reason', label: t('b2b::admin_companies.reason'), value: form.data.reason, rules: { required: true, length: { max: 1000 } } }], open);

    return (
        <DestructiveActionDialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
            title={t('b2b::admin_companies.suspend.title')}
            description={t('b2b::admin_companies.suspend.body')}
            verificationPhrase={companyName}
            verificationLabel={t('b2b::admin_companies.suspend.name_label')}
            returnFocusTo={returnFocusTo}
            // A reason written is kept from a stray click outside, as a typed name is.
            dirty={form.data.reason !== ''}
            confirmLabel={t('b2b::admin_companies.suspend.button')}
            loading={form.processing}
            error={refusal}
            waitingFor={(form.data.reason.trim() === '' ? t('b2b::admin_companies.reason_missing') : undefined) ?? checks.reason}
            body={
                <TextareaField
                    id="suspend-reason"
                    rows={3}
                    label={t('b2b::admin_companies.reason')}
                    helper={t('b2b::admin_companies.suspend.reason_helper')}
                    check={checks.box('suspend-reason', form.errors.reason)}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    data-test="suspend-reason"
                />
            }
            onConfirm={() =>
                checks.submit(() =>
                    form.post(url(companyId, 'suspend'), {
                        preserveScroll: true,
                        onSuccess: () => {
                            onOpenChange(false);
                            form.reset();
                        },
                    }),
                )
            }
        />
    );
}

/** Back to the status it held before the suspension, with a reason; no email is sent (§2.3). */
export function ReinstateModal({ open, onOpenChange, companyId, returnsTo }: Base & { returnsTo: string }) {
    const t = useTranslator();
    const form = useForm({ reason: '' });

    return (
        <ReasonDialog
            open={open}
            onOpenChange={onOpenChange}
            form={form}
            action={url(companyId, 'reinstate')}
            name="reinstate"
            title={t('b2b::admin_companies.reinstate.title')}
            body={t('b2b::admin_companies.reinstate.body', { status: returnsTo })}
            helper={t('b2b::admin_companies.reinstate.reason_helper')}
            button={t('b2b::admin_companies.reinstate.button')}
        />
    );
}

type ReasonForm = InertiaFormProps<{ reason: string }>;

function ReasonDialog({
    open,
    onOpenChange,
    form,
    action,
    name,
    title,
    body,
    helper,
    button,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    form: ReasonForm;
    action: string;
    name: string;
    title: string;
    body: string;
    helper: string;
    button: string;
}) {
    const t = useTranslator();
    // The reason as typed (frontend.md §1.7): required, at most 1,000 characters (Remark::MAX).
    const checks = useChecks([{ id: `${name}-reason`, label: t('b2b::admin_companies.reason'), value: form.data.reason, rules: { required: true, length: { max: 1000 } } }], open);

    function submit() {
        checks.submit(() =>
            form.post(action, {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            }),
        );
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={body}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={(form.data.reason.trim() === '' ? t('b2b::admin_companies.reason_missing') : undefined) ?? checks.reason}
                    onClick={submit}
                    data-test={`confirm-${name}`}
                >
                    {button}
                </ActionButton>
            }
        >
            <TextareaField
                id={`${name}-reason`}
                rows={3}
                label={t('b2b::admin_companies.reason')}
                helper={helper}
                check={checks.box(`${name}-reason`, form.errors.reason)}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                data-test={`${name}-reason`}
            />
        </PanelDialog>
    );
}

const OTHER = '__other';

/**
 * A listed type of the home store, or "Other" in words (never for an approved company, 13(b)). A
 * deactivated type is offered only to someone who may also activate types, and choosing it says it
 * becomes active again before it is assigned (amendment 8(b)).
 */
export function CorrectTypeModal({
    open,
    onOpenChange,
    companyId,
    returnFocusTo,
    choices,
    mayChooseOther,
    currentTypeId,
    currentOther,
    locale,
}: Base & {
    choices: StaffTypeChoiceData[];
    mayChooseOther: boolean;
    currentTypeId: string | null;
    currentOther: string | null;
    locale: Locale;
}) {
    const t = useTranslator();
    // Nothing chosen when it opens: pressing the button without choosing must never post the type
    // the company holds - which, deactivated, would be activated for the whole store and change
    // nothing here (the review of step 7).
    const [choice, setChoice] = useState<string>('');
    const form = useForm({ type_id: '', other: currentOther ?? '', confirm_reactivation: false });

    useEffect(() => {
        if (open) {
            setChoice('');
            form.setData('other', currentOther ?? '');
            form.clearErrors();
        }
        // Keyed on the opening only: the form itself changes on every keystroke.
    }, [open]);

    const unchanged =
        choice === '' ||
        (choice === OTHER ? form.data.other.trim() === '' || form.data.other.trim() === (currentOther ?? '').trim() : choice === currentTypeId);
    const chosen = choices.find((each) => each.id === choice) ?? null;
    const reactivates = chosen !== null && !chosen.active;
    // "Other" in words as typed (frontend.md §1.7): required, one line of at most 100 characters
    // (CompanyTypeChoice::OTHER_MAX) - checked only while Other is chosen, the only time it is sent.
    const checks = useChecks([
        { id: 'correct-other', label: t('b2b::admin_companies.correct.other_words'), value: form.data.other, rules: { required: true, length: { max: 100 } }, off: choice !== OTHER },
    ], open);

    function submit() {
        form.transform((data) =>
            choice === OTHER ? { type_id: '', other: data.other, confirm_reactivation: false } : { type_id: choice, other: '', confirm_reactivation: reactivates },
        );
        checks.submit(() =>
            form.post(url(companyId, 'type'), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            }),
        );
    }

    const typeError = (form.errors as Record<string, string | undefined>).type;

    return (
        <PanelDialog
            open={open}
            returnFocusTo={returnFocusTo}
            onOpenChange={onOpenChange}
            title={t('b2b::admin_companies.correct.title')}
            description={t('b2b::admin_companies.correct.body')}
            busy={form.processing}
            confirm={
                <ActionButton
                    loading={form.processing}
                    disabledReason={(unchanged ? t('b2b::admin_companies.correct.choose_first') : undefined) ?? checks.reason}
                    onClick={submit}
                    data-test="confirm-correct-type"
                >
                    {t('b2b::admin_companies.correct.button')}
                </ActionButton>
            }
        >
            <SelectField id="correct-type" label={t('b2b::admin_companies.correct.type')} value={choice} error={typeError} onChange={(event) => setChoice(event.target.value)} data-test="correct-type-choice">
                <NativeSelectOption value="" disabled>
                    {t('b2b::admin_companies.correct.choose')}
                </NativeSelectOption>
                {choices.map((each) => (
                    <NativeSelectOption key={each.id} value={each.id}>
                        {each.active
                            ? nameIn(locale, each.nameAr, each.nameEn)
                            : t('b2b::admin_companies.correct.deactivated', { name: nameIn(locale, each.nameAr, each.nameEn) })}
                    </NativeSelectOption>
                ))}
                {mayChooseOther ? <NativeSelectOption value={OTHER}>{t('b2b::admin_companies.correct.other')}</NativeSelectOption> : null}
            </SelectField>

            {choice === OTHER ? (
                <TextField
                    id="correct-other"
                    label={t('b2b::admin_companies.correct.other_words')}
                    value={form.data.other}
                    check={checks.box('correct-other', form.errors.other)}
                    onChange={(event) => form.setData('other', event.target.value)}
                    data-test="correct-type-other"
                />
            ) : null}

            {reactivates ? (
                <Note variant="warning" size="small" label={t('b2b::admin_companies.correct.reactivates_label')} data-test="reactivates">
                    {t('b2b::admin_companies.correct.reactivates')}
                </Note>
            ) : null}
        </PanelDialog>
    );
}
