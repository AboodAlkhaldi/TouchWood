import { type ChangeEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { isolate } from '@/lib/bidi';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type { SharedProps } from '@/types/page';
import type {
    CompanyApplicationData,
    CompanyDraftData,
    CompanyFileData,
    CompanyPage,
    CompanyRequestData,
    CompanyTypeOptionData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, nameOf, useLocale, when } from './parts';

/*
| The company form (b2b.md §4.5, amendment 14(a)): company details, documents, what the last
| rejection asked for, and a note — then Send.
|
| **It saves itself.** A field is saved when the person leaves it, a file the moment it is chosen,
| so a value the domain refuses is shown on its own field while they are still there (amendment 4)
| and nothing is lost by walking away. Each save sends that one field; the rest of the draft keeps
| what it holds. Only Send asks whether it is complete.
|
| After a rejection, what it marked is marked here until it is replaced — a field once it differs
| from what was sent, a paper once a new file is under its type (§1.2).
*/

type Props = {
    page: CompanyPage;
    draft: CompanyDraftData;
    /** The application the rejection decided, whose flags and requests this draft answers. */
    lastSent: CompanyApplicationData | null;
    /** An approved company changing its details: said at the top and above Send (§1.1). */
    changing: boolean;
};

const DETAILS = ['name', 'cr_number', 'tax_number', 'address'] as const;
const OTHER = 'other';

export function CompanyForm({ page, draft, lastSent, changing }: Props) {
    const t = useTranslator();
    const link = useLink();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);

    const flagged = (field: string): boolean => draft.flags.some((flag) => flag.field === field);
    const sentValue = (field: string): string | null => {
        const values = lastSent?.values;

        if (values === undefined) {
            return null;
        }

        return (
            {
                name: values.name,
                company_type: values.companyTypeId ?? values.companyTypeOther,
                cr_number: values.crNumber,
                tax_number: values.taxNumber,
                address: values.address,
            } as Record<string, string | null>
        )[field] ?? null;
    };
    const valueOf = (field: string): string | null =>
        (
            {
                name: draft.values.name,
                company_type: draft.values.companyTypeId ?? draft.values.companyTypeOther,
                cr_number: draft.values.crNumber,
                tax_number: draft.values.taxNumber,
                address: draft.values.address,
            } as Record<string, string | null>
        )[field] ?? null;
    // Replaced when it differs, exactly after trimming, from what the rejection saw (§3.1).
    const stillFlagged = (field: string): boolean =>
        flagged(field) && (valueOf(field) ?? '').trim() === (sentValue(field) ?? '').trim();

    const required = page.documentTypes.filter((type) => type.required && !type.greyed);
    const held = (typeId: string): CompanyFileData | undefined =>
        draft.documents.find((document) => document.documentTypeId === typeId);
    const done = required.filter((type) => held(type.id) !== undefined).length;
    // A held paper under a type no longer on the list: it must be removed before sending.
    const orphans = draft.documents.filter((document) => !page.documentTypes.some((type) => type.id === document.documentTypeId));

    return (
        <div className="grid gap-6" data-test="company-form">
            {changing ? <Warning /> : null}

            <Card title={t('b2b::company.section.details')} hint={t('b2b::company.section.details_hint')} test="details">
                <div className="grid gap-4">
                    <SavedText
                        field="name"
                        label={t('b2b::company.field.name')}
                        saved={draft.values.name}
                        error={errors.name}
                        flagged={stillFlagged('name')}
                    />
                    <TypeChoice
                        options={page.companyTypes}
                        draft={draft}
                        error={errors.company_type}
                        flagged={stillFlagged('company_type')}
                        locale={locale}
                    />
                    {DETAILS.filter((field) => field !== 'name').map((field) => (
                        <SavedText
                            key={field}
                            field={field}
                            label={t(`b2b::company.field.${field}`)}
                            saved={
                                field === 'cr_number' ? draft.values.crNumber : field === 'tax_number' ? draft.values.taxNumber : draft.values.address
                            }
                            error={errors[field]}
                            flagged={stillFlagged(field)}
                            multiline={field === 'address'}
                            figures={field !== 'address'}
                        />
                    ))}
                </div>
            </Card>

            <Card
                title={t('b2b::company.section.documents')}
                hint={t('b2b::company.documents_hint', { size: Math.round(page.maxFileBytes / 1048576) })}
                test="documents"
            >
                <p className="text-xs text-ink-muted" data-test="documents-done">
                    {t('b2b::company.documents_done', { done, total: required.length })}
                </p>
                <ul className="grid gap-3">
                    {page.documentTypes.map((type) => (
                        <DocumentRow
                            key={type.id}
                            type={type}
                            file={held(type.id)}
                            flagged={draft.flags.some((flag) => flag.documentTypeId === type.id)}
                            sentMediaId={lastSent?.documents.find((document) => document.documentTypeId === type.id)?.mediaId ?? null}
                            error={errors[`documents.${type.id}`]}
                            maxBytes={page.maxFileBytes}
                            locale={locale}
                        />
                    ))}
                    {orphans.map((file) => (
                        <DocumentRow
                            key={file.documentTypeId}
                            type={{ id: file.documentTypeId, nameAr: file.documentTypeNameAr ?? '', nameEn: file.documentTypeNameEn ?? '', greyed: true, required: false }}
                            file={file}
                            flagged={false}
                            sentMediaId={null}
                            error={errors[`documents.${file.documentTypeId}`]}
                            maxBytes={page.maxFileBytes}
                            locale={locale}
                        />
                    ))}
                </ul>
            </Card>

            {draft.requests.length > 0 ? (
                <Card title={t('b2b::company.section.requests')} hint={t('b2b::company.requests_hint')} test="requests">
                    <ul className="grid gap-4">
                        {draft.requests.map((request) => (
                            <RequestRow
                                key={request.id}
                                request={request}
                                answer={draft.answers.find((answer) => answer.requestId === request.id) ?? null}
                                error={errors[`answers.${request.id}`]}
                                maxBytes={page.maxFileBytes}
                            />
                        ))}
                    </ul>
                </Card>
            ) : null}

            <Card title={t('b2b::company.section.note')} test="note">
                <SavedText field="note" label={t('b2b::company.field.note')} saved={draft.values.note} error={errors.note} multiline />
            </Card>

            <div className="grid gap-3">
                {changing ? <Warning /> : null}

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        data-test="send"
                        disabled={done < required.length}
                        onClick={() => router.post(link('storefront.company.send'), {}, { preserveScroll: true })}
                    >
                        {done < required.length
                            ? t('b2b::company.send_missing', { done, total: required.length })
                            : t('b2b::company.send')}
                    </Button>
                    <Button type="button" variant="outline" data-test="discard" onClick={() => setConfirmingDiscard((open) => !open)}>
                        {t('b2b::company.discard')}
                    </Button>
                </div>

                {confirmingDiscard ? <DiscardConfirmation onCancel={() => setConfirmingDiscard(false)} /> : null}
            </div>
        </div>
    );
}

/** What sending a change does to an approved company, before it is sent (§1.1, amendment 14(e)). */
function Warning() {
    const t = useTranslator();

    return (
        <p data-test="change-warning" className="rounded-md border border-warn/40 bg-warn-soft px-4 py-3 text-sm text-ink">
            {t('b2b::company.change_warning')}
        </p>
    );
}

/** Asked in the page, never with the browser's own box (owner, 2026-09-24). */
export function DiscardConfirmation({ onCancel }: { onCancel: () => void }) {
    const t = useTranslator();
    const link = useLink();

    return (
        <div className="grid gap-3 rounded-lg border border-bad/30 bg-bad-soft p-4" data-test="discard-confirmation">
            <p className="text-sm text-ink">{t('b2b::company.discard_confirm')}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    data-test="discard-confirm"
                    onClick={() => router.post(link('storefront.company.discard'))}
                >
                    {t('b2b::company.discard_yes')}
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={onCancel}>
                    {t('b2b::company.cancel')}
                </Button>
            </div>
        </div>
    );
}

/**
 * Saves one field of the draft; the page comes back with the draft as it now stands, and the field's
 * own message if the value was refused.
 */
function useSave(): { saving: string | null; save: (key: string, fields: Record<string, string | null>) => void } {
    const link = useLink();
    const [saving, setSaving] = useState<string | null>(null);

    return {
        saving,
        save: (key, fields) => {
            setSaving(key);
            router.post(link('storefront.company.save'), fields, {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(null),
            });
        },
    };
}

type SavedTextProps = {
    field: string;
    label: string;
    saved: string | null;
    error?: string;
    flagged?: boolean;
    multiline?: boolean;
    figures?: boolean;
};

/** A text field that saves itself when the person leaves it — only when it changed. */
function SavedText({ field, label, saved, error, flagged = false, multiline = false, figures = false }: SavedTextProps) {
    const t = useTranslator();
    const { saving, save } = useSave();
    const [value, setValue] = useState(saved ?? '');

    // The draft as the server now holds it, whenever that changes — not on every re-render, which
    // would throw away a refused value the person is still correcting.
    useEffect(() => setValue(saved ?? ''), [saved]);

    const leave = () => {
        if (value !== (saved ?? '')) {
            save(field, { [field]: value.trim() === '' ? null : value });
        }
    };

    const id = `company-${field}`;
    const common = {
        id,
        name: field,
        value,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error ? `${id}-error` : undefined,
        'data-test': `field-${field}`,
        dir: figures ? ('ltr' as const) : undefined,
        onBlur: leave,
    };

    return (
        <Field id={id} label={label} error={error}>
            {multiline ? (
                <textarea
                    {...common}
                    rows={3}
                    onChange={(event) => setValue(event.target.value)}
                    className="w-full rounded-md border border-line-strong bg-surface p-3 text-sm text-ink"
                />
            ) : (
                <Input {...common} onChange={(event) => setValue(event.target.value)} />
            )}
            <FieldState saving={saving === field} flagged={flagged} message={t('b2b::company.flagged')} />
        </Field>
    );
}

function FieldState({ saving, flagged, message }: { saving: boolean; flagged: boolean; message: string }) {
    const t = useTranslator();

    return (
        <>
            {flagged ? (
                <p className="mt-1 text-xs text-bad" data-test="flagged">
                    {message}
                </p>
            ) : null}
            {saving ? <p className="mt-1 text-xs text-ink-muted">{t('b2b::company.saving')}</p> : null}
        </>
    );
}

/**
 * The company type: one of the home store's, or "Other" and the company's own words (§1.3). A
 * greyed type is shown and cannot be chosen. Choosing "Other" clears the listed type at once; the
 * words are saved when the person leaves them.
 */
function TypeChoice({
    options,
    draft,
    error,
    flagged,
    locale,
}: {
    options: CompanyTypeOptionData[];
    draft: CompanyDraftData;
    error?: string;
    flagged: boolean;
    locale: 'ar' | 'en';
}) {
    const t = useTranslator();
    const { saving, save } = useSave();
    const savedChoice = draft.values.companyTypeId ?? (draft.values.companyTypeOther !== null ? OTHER : '');
    const [choice, setChoice] = useState(savedChoice);
    const [words, setWords] = useState(draft.values.companyTypeOther ?? '');

    useEffect(() => setChoice(savedChoice), [savedChoice]);
    useEffect(() => setWords(draft.values.companyTypeOther ?? ''), [draft.values.companyTypeOther]);

    return (
        <div className="grid gap-3">
            <Field id="company-type" label={t('b2b::company.field.company_type')} error={error}>
                <select
                    id="company-type"
                    data-test="field-company_type"
                    value={choice}
                    aria-invalid={error ? true : undefined}
                    onChange={(event) => {
                        const chosen = event.target.value;
                        setChoice(chosen);

                        if (chosen === OTHER) {
                            save('company_type', { company_type_id: null, company_type_other: words.trim() === '' ? null : words });
                        } else {
                            save('company_type', { company_type_id: chosen === '' ? null : chosen, company_type_other: null });
                        }
                    }}
                    className="h-9 w-full rounded-md border border-line-strong bg-surface px-3 text-sm text-ink"
                >
                    <option value="">{t('b2b::company.field.choose')}</option>
                    {options.map((option) => (
                        <option key={option.id} value={option.id} disabled={option.greyed}>
                            {option.greyed ? `${nameOf(option, locale)} — ${t('b2b::company.greyed')}` : nameOf(option, locale)}
                        </option>
                    ))}
                    {/* Always last, whatever staff set up: it is not a type (§1.3). */}
                    <option value={OTHER}>{t('b2b::company.field.other')}</option>
                </select>
                {draft.typeNoLongerAccepted ? (
                    <p className="mt-1 text-xs text-bad" data-test="type-no-longer">
                        {t('b2b::company.type_no_longer')}
                    </p>
                ) : null}
                <FieldState saving={saving === 'company_type' && choice !== OTHER} flagged={flagged} message={t('b2b::company.flagged')} />
            </Field>

            {choice === OTHER ? (
                <Field id="company-type-other" label={t('b2b::company.field.other_words')}>
                    <Input
                        id="company-type-other"
                        data-test="field-company_type_other"
                        value={words}
                        onChange={(event) => setWords(event.target.value)}
                        onBlur={() => {
                            if (words !== (draft.values.companyTypeOther ?? '')) {
                                save('company_type', { company_type_id: null, company_type_other: words.trim() === '' ? null : words });
                            }
                        }}
                    />
                </Field>
            ) : null}
        </div>
    );
}

/** A file input the person never sees: the button opens it, and choosing a file sends it. */
function useFilePicker(
    url: string,
    maxBytes: number,
): { input: RefObject<HTMLInputElement | null>; pick: () => void; tooLarge: boolean; onChange: (event: ChangeEvent<HTMLInputElement>) => void; sending: boolean } {
    const input = useRef<HTMLInputElement | null>(null);
    const [tooLarge, setTooLarge] = useState(false);
    const [sending, setSending] = useState(false);

    return {
        input,
        pick: () => input.current?.click(),
        tooLarge,
        sending,
        onChange: (event) => {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (file === undefined) {
                return;
            }

            // Said at once, rather than after sending ten megabytes the server will refuse.
            if (file.size > maxBytes) {
                setTooLarge(true);

                return;
            }

            setTooLarge(false);
            setSending(true);
            router.post(url, { file }, { forceFormData: true, preserveScroll: true, preserveState: true, onFinish: () => setSending(false) });
        },
    };
}

const ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';

function DocumentRow({
    type,
    file,
    flagged,
    sentMediaId,
    error,
    maxBytes,
    locale,
}: {
    type: CompanyTypeOptionData;
    file: CompanyFileData | undefined;
    flagged: boolean;
    sentMediaId: string | null;
    error?: string;
    maxBytes: number;
    locale: 'ar' | 'en';
}) {
    const t = useTranslator();
    const link = useLink();
    const picker = useFilePicker(link('storefront.company.attach', { type: type.id }), maxBytes);
    const megabytes = Math.round(maxBytes / 1048576);
    // Replaced once the file under the type is not the one the rejection saw (§3.1).
    const stillFlagged = flagged && (file === undefined || file.mediaId === sentMediaId);

    return (
        <li className="grid gap-2 rounded-md border border-line p-3" data-test={`document-${type.id}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium text-ink">{nameOf(type, locale)}</span>
                <span className="text-xs text-ink-muted">
                    {file !== undefined
                        ? t('b2b::company.uploaded', { date: isolate(when(file.uploadedAt)) })
                        : type.greyed
                          ? t('b2b::company.greyed')
                          : type.required
                            ? t('b2b::company.required')
                            : t('b2b::company.optional')}
                </span>
            </div>

            <div className="flex flex-wrap gap-2">
                {type.greyed ? null : (
                    <>
                        <input ref={picker.input} type="file" accept={ACCEPT} className="hidden" onChange={picker.onChange} data-test={`file-${type.id}`} />
                        <Button type="button" variant="outline" size="sm" disabled={picker.sending} onClick={picker.pick}>
                            {file === undefined ? t('b2b::company.choose_file') : t('b2b::company.replace')}
                        </Button>
                    </>
                )}
                {file !== undefined ? (
                    <>
                        <Button asChild variant="ghost" size="sm">
                            <a href={link('storefront.company.file', { file: file.mediaId })} target="_blank" rel="noreferrer">
                                {t('b2b::company.open')}
                            </a>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test={`remove-${type.id}`}
                            onClick={() => router.post(link('storefront.company.detach', { type: type.id }), {}, { preserveScroll: true, preserveState: true })}
                        >
                            {t('b2b::company.remove')}
                        </Button>
                    </>
                ) : null}
            </div>

            {file?.noLongerAccepted ? <p className="text-xs text-bad">{t('b2b::company.no_longer_accepted')}</p> : null}
            {stillFlagged ? <p className="text-xs text-bad" data-test="flagged">{t('b2b::company.flagged_document')}</p> : null}
            {picker.tooLarge ? <p className="text-xs text-bad">{t('b2b::company.too_large', { size: megabytes })}</p> : null}
            {error ? (
                <p role="alert" className="text-xs text-bad">
                    {error}
                </p>
            ) : null}
        </li>
    );
}

function RequestRow({
    request,
    answer,
    error,
    maxBytes,
}: {
    request: CompanyRequestData;
    answer: { text: string | null; mediaId: string | null } | null;
    error?: string;
    maxBytes: number;
}) {
    const t = useTranslator();
    const link = useLink();
    const url = link('storefront.company.answer', { answered: request.id });
    const picker = useFilePicker(url, maxBytes);
    const [text, setText] = useState(answer?.text ?? '');
    const [saving, setSaving] = useState(false);

    useEffect(() => setText(answer?.text ?? ''), [answer?.text]);

    return (
        <li className="grid gap-2" data-test={`request-${request.id}`}>
            <p className="text-sm font-medium text-ink">{request.label}</p>

            {request.kind === 'TEXT' ? (
                <textarea
                    aria-label={t('b2b::company.answer')}
                    rows={3}
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                    onBlur={() => {
                        if (text === (answer?.text ?? '')) {
                            return;
                        }

                        setSaving(true);
                        router.post(
                            text.trim() === '' ? link('storefront.company.unanswer', { answered: request.id }) : url,
                            text.trim() === '' ? {} : { text },
                            { preserveScroll: true, preserveState: true, onFinish: () => setSaving(false) },
                        );
                    }}
                    className="w-full rounded-md border border-line-strong bg-surface p-3 text-sm text-ink"
                />
            ) : (
                <div className="flex flex-wrap gap-2">
                    <input ref={picker.input} type="file" accept={ACCEPT} className="hidden" onChange={picker.onChange} />
                    <Button type="button" variant="outline" size="sm" disabled={picker.sending} onClick={picker.pick}>
                        {answer?.mediaId ? t('b2b::company.replace') : t('b2b::company.choose_file')}
                    </Button>
                    {answer?.mediaId ? (
                        <>
                            <Button asChild variant="ghost" size="sm">
                                <a href={link('storefront.company.file', { file: answer.mediaId })} target="_blank" rel="noreferrer">
                                    {t('b2b::company.open')}
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => router.post(link('storefront.company.unanswer', { answered: request.id }), {}, { preserveScroll: true, preserveState: true })}
                            >
                                {t('b2b::company.remove')}
                            </Button>
                        </>
                    ) : null}
                </div>
            )}

            {saving ? <p className="text-xs text-ink-muted">{t('b2b::company.saving')}</p> : null}
            {picker.tooLarge ? (
                <p className="text-xs text-bad">{t('b2b::company.too_large', { size: Math.round(maxBytes / 1048576) })}</p>
            ) : null}
            {error ? (
                <p role="alert" className="text-xs text-bad">
                    {error}
                </p>
            ) : null}
        </li>
    );
}
