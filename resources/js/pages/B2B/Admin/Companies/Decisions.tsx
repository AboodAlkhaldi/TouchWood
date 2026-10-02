import { useState } from 'react';
import { useForm, type InertiaFormProps } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import {
    Button,
    Checkbox,
    Input,
    Modal,
    ModalCancel,
    Note,
    Select,
    Textarea,
} from '@/components/geist';
import { useTranslator } from '@/lib/t';
import type {
    CompanyFileData,
    StaffTypeChoiceData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { nameIn, type Locale } from '../shared';

/*
| The decisions on one company (b2b.md §3.2, §4.6, amendment 19), each in Geist's Modal: its title a
| statement, its primary button repeating it, the toast answering with the same verb.
|
| Reject and Suspend are destructive - focus starts on Cancel, an outside click does not close them,
| and the button wakes only once a reason is written - but not the typed confirmation: both can be
| undone, by applying again or by reinstating (19(g)). Every modal stays open on a refusal, with the
| refusal beside the field it names or at the top of the page, and closes once the server agreed.
*/

type Base = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyId: string;
};

const FIELDS = ['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const;

function url(companyId: string, action: string): string {
    return `/admin/companies/${companyId}/${action}`;
}

export function ApproveModal({ open, onOpenChange, companyId, typeNote }: Base & { typeNote: string | null }) {
    const t = useTranslator();
    const form = useForm({ note: '' });

    function submit() {
        form.post(url(companyId, 'approve'), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={t('b2b::admin_companies.approve.title')}
            description={t('b2b::admin_companies.approve.body')}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button loading={form.processing} onClick={submit} data-test="confirm-approve">
                        {t('b2b::admin_companies.approve.button')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                {typeNote === null ? null : (
                    <Note variant="warning" size="small" label={t('b2b::admin_companies.application.type_changed.label')}>
                        {typeNote}
                    </Note>
                )}
                <Textarea
                    id="approve-note"
                    rows={3}
                    label={t('b2b::admin_companies.approve.note')}
                    helper={t('b2b::admin_companies.approve.note_helper')}
                    error={form.errors.note}
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    data-test="approve-note"
                />
            </div>
        </Modal>
    );
}

type RejectForm = {
    reason: string;
    flags: string[];
    documents: string[];
    requests: { kind: string; label: string }[];
};

/**
 * A reason, and what the next application must fix or add (§1.2, amendment 4): any of the five
 * fields, any paper the waiting application sent, and requests for a text or a file under a label.
 */
export function RejectModal({ open, onOpenChange, companyId, papers, locale }: Base & { papers: CompanyFileData[]; locale: Locale }) {
    const t = useTranslator();
    const form = useForm<RejectForm>({ reason: '', flags: [], documents: [], requests: [] });

    function toggle(list: 'flags' | 'documents', value: string, on: boolean) {
        const current = form.data[list];
        form.setData(list, on ? [...current, value] : current.filter((item) => item !== value));
    }

    function submit() {
        form.post(url(companyId, 'reject'), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            destructive
            size="large"
            title={t('b2b::admin_companies.reject.title')}
            description={t('b2b::admin_companies.reject.body')}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button
                        type="error"
                        loading={form.processing}
                        disabledReason={form.data.reason.trim() === '' ? t('b2b::admin_companies.reason_missing') : undefined}
                        onClick={submit}
                        data-test="confirm-reject"
                    >
                        {t('b2b::admin_companies.reject.button')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-5">
                <Textarea
                    id="reject-reason"
                    rows={3}
                    label={t('b2b::admin_companies.reason')}
                    helper={t('b2b::admin_companies.reject.reason_helper')}
                    error={form.errors.reason}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    data-test="reject-reason"
                />

                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-label-14 font-medium text-ink">{t('b2b::admin_companies.reject.flags')}</legend>
                    <p className="text-copy-13 text-ink-muted">{t('b2b::admin_companies.reject.flags_helper')}</p>
                    {FIELDS.map((field) => (
                        <Checkbox
                            key={field}
                            id={`flag-${field}`}
                            checked={form.data.flags.includes(field)}
                            onChange={(on) => toggle('flags', field, on)}
                            data-test={`flag-${field}`}
                        >
                            {t(`b2b::admin_companies.application.flag.${field}`)}
                        </Checkbox>
                    ))}
                    {papers.map((paper) => (
                        <Checkbox
                            key={paper.documentTypeId}
                            id={`flag-document-${paper.documentTypeId}`}
                            checked={form.data.documents.includes(paper.documentTypeId)}
                            onChange={(on) => toggle('documents', paper.documentTypeId, on)}
                            data-test={`flag-document-${paper.documentTypeId}`}
                        >
                            {nameIn(locale, paper.documentTypeNameAr, paper.documentTypeNameEn) || t('b2b::admin_companies.application.paper')}
                        </Checkbox>
                    ))}
                    {form.errors.flags ? (
                        <p role="alert" className="text-copy-13 text-bad">
                            {form.errors.flags}
                        </p>
                    ) : null}
                </fieldset>

                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-label-14 font-medium text-ink">{t('b2b::admin_companies.reject.requests')}</legend>
                    <p className="text-copy-13 text-ink-muted">{t('b2b::admin_companies.reject.requests_helper')}</p>
                    {form.data.requests.map((request, index) => (
                        <div key={index} className="flex flex-wrap items-end gap-2" data-test={`request-${index}`}>
                            <Select
                                id={`request-kind-${index}`}
                                className="w-32"
                                label={t('b2b::admin_companies.reject.request_kind')}
                                value={request.kind}
                                onChange={(event) =>
                                    form.setData(
                                        'requests',
                                        form.data.requests.map((each, at) => (at === index ? { ...each, kind: event.target.value } : each)),
                                    )
                                }
                            >
                                <option value="TEXT">{t('b2b::admin_companies.application.kind.TEXT')}</option>
                                <option value="FILE">{t('b2b::admin_companies.application.kind.FILE')}</option>
                            </Select>
                            <Input
                                id={`request-label-${index}`}
                                className="min-w-48 flex-1"
                                label={t('b2b::admin_companies.reject.request_label')}
                                placeholder={t('b2b::admin_companies.reject.request_label_placeholder')}
                                value={request.label}
                                onChange={(event) =>
                                    form.setData(
                                        'requests',
                                        form.data.requests.map((each, at) => (at === index ? { ...each, label: event.target.value } : each)),
                                    )
                                }
                                data-test={`request-label-${index}`}
                            />
                            <Button
                                type="tertiary"
                                svgOnly
                                aria-label={t('b2b::admin_companies.reject.remove_request', { number: index + 1 })}
                                onClick={() => form.setData('requests', form.data.requests.filter((_, at) => at !== index))}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                    ))}
                    {form.errors.requests ? (
                        <p role="alert" className="text-copy-13 text-bad">
                            {form.errors.requests}
                        </p>
                    ) : null}
                    <div>
                        <Button
                            type="secondary"
                            size="small"
                            prefix={<Plus className="size-4" />}
                            onClick={() => form.setData('requests', [...form.data.requests, { kind: 'TEXT', label: '' }])}
                            data-test="add-request"
                        >
                            {t('b2b::admin_companies.reject.add_request')}
                        </Button>
                    </div>
                </fieldset>
            </div>
        </Modal>
    );
}

/** Suspending from any status, with a reason the customer is emailed (§4.1). */
export function SuspendModal({ open, onOpenChange, companyId }: Base) {
    const t = useTranslator();
    const form = useForm({ reason: '' });

    return (
        <ReasonModal
            open={open}
            onOpenChange={onOpenChange}
            form={form}
            action={url(companyId, 'suspend')}
            name="suspend"
            destructive
            title={t('b2b::admin_companies.suspend.title')}
            body={t('b2b::admin_companies.suspend.body')}
            helper={t('b2b::admin_companies.suspend.reason_helper')}
            button={t('b2b::admin_companies.suspend.button')}
        />
    );
}

/** Back to the status it held before the suspension, with a reason; no email is sent (§2.3). */
export function ReinstateModal({ open, onOpenChange, companyId, returnsTo }: Base & { returnsTo: string }) {
    const t = useTranslator();
    const form = useForm({ reason: '' });

    return (
        <ReasonModal
            open={open}
            onOpenChange={onOpenChange}
            form={form}
            action={url(companyId, 'reinstate')}
            name="reinstate"
            destructive={false}
            title={t('b2b::admin_companies.reinstate.title')}
            body={t('b2b::admin_companies.reinstate.body', { status: returnsTo })}
            helper={t('b2b::admin_companies.reinstate.reason_helper')}
            button={t('b2b::admin_companies.reinstate.button')}
        />
    );
}

type ReasonForm = InertiaFormProps<{ reason: string }>;

function ReasonModal({
    open,
    onOpenChange,
    form,
    action,
    name,
    destructive,
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
    destructive: boolean;
    title: string;
    body: string;
    helper: string;
    button: string;
}) {
    const t = useTranslator();

    function submit() {
        form.post(action, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
            },
        });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            destructive={destructive}
            title={title}
            description={body}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button
                        type={destructive ? 'error' : 'default'}
                        loading={form.processing}
                        disabledReason={form.data.reason.trim() === '' ? t('b2b::admin_companies.reason_missing') : undefined}
                        onClick={submit}
                        data-test={`confirm-${name}`}
                    >
                        {button}
                    </Button>
                </>
            }
        >
            <Textarea
                id={`${name}-reason`}
                rows={3}
                label={t('b2b::admin_companies.reason')}
                helper={helper}
                error={form.errors.reason}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                data-test={`${name}-reason`}
            />
        </Modal>
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
    const [choice, setChoice] = useState<string>(currentOther !== null && mayChooseOther ? OTHER : (currentTypeId ?? ''));
    const form = useForm({ type_id: '', other: currentOther ?? '', confirm_reactivation: false });
    const chosen = choices.find((each) => each.id === choice) ?? null;
    const reactivates = chosen !== null && !chosen.active;

    function submit() {
        form.transform((data) =>
            choice === OTHER
                ? { type_id: '', other: data.other, confirm_reactivation: false }
                : { type_id: choice, other: '', confirm_reactivation: reactivates },
        );
        form.post(url(companyId, 'type'), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    const typeError = (form.errors as Record<string, string | undefined>).type;

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={t('b2b::admin_companies.correct.title')}
            description={t('b2b::admin_companies.correct.body')}
            actions={
                <>
                    <ModalCancel onClick={() => onOpenChange(false)} />
                    <Button loading={form.processing} onClick={submit} data-test="confirm-correct-type">
                        {t('b2b::admin_companies.correct.button')}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4">
                <Select
                    id="correct-type"
                    label={t('b2b::admin_companies.correct.type')}
                    placeholder={t('b2b::admin_companies.correct.choose')}
                    value={choice}
                    error={typeError}
                    onChange={(event) => setChoice(event.target.value)}
                    data-test="correct-type-choice"
                >
                    {choices.map((each) => (
                        <option key={each.id} value={each.id}>
                            {each.active
                                ? nameIn(locale, each.nameAr, each.nameEn)
                                : t('b2b::admin_companies.correct.deactivated', { name: nameIn(locale, each.nameAr, each.nameEn) })}
                        </option>
                    ))}
                    {mayChooseOther ? <option value={OTHER}>{t('b2b::admin_companies.correct.other')}</option> : null}
                </Select>

                {choice === OTHER ? (
                    <Input
                        id="correct-other"
                        label={t('b2b::admin_companies.correct.other_words')}
                        value={form.data.other}
                        error={form.errors.other}
                        onChange={(event) => form.setData('other', event.target.value)}
                        data-test="correct-type-other"
                    />
                ) : null}

                {reactivates ? (
                    <Note variant="warning" size="small" label={t('b2b::admin_companies.correct.reactivates_label')} data-test="reactivates">
                        {t('b2b::admin_companies.correct.reactivates')}
                    </Note>
                ) : null}
            </div>
        </Modal>
    );
}

