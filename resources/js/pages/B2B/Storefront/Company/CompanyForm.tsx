import { type ChangeEvent, createContext, type RefObject, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Field } from '@/components/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { isolate } from '@/lib/bidi';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyAnswerData,
    CompanyApplicationData,
    CompanyDraftData,
    CompanyFileData,
    CompanyPage,
    CompanyRequestData,
    CompanyTypeOptionData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { Card, nameOf, useLocale, when } from './parts';

/*
| The company form (b2b.md §4.5, amendments 14(a) and 15(a)): company details, documents, what the
| last rejection asked for, and a note — then Send.
|
| **It saves itself.** A field is saved when the person leaves it, a file the moment it is chosen,
| so a value the domain refuses is shown on its own field while they are still there (amendment 4)
| and nothing is lost by walking away. Each save sends that one field; the rest of the draft keeps
| what it holds. Only Send asks whether it is complete.
|
| **One change at a time, in the order made.** Every save, upload and removal waits in one queue:
| a page request started while another runs would cancel it, and a save, a refusal or a paper would
| be lost without a word (the review of step 6). Each field keeps its own refusal until it is saved
| again — the next field's answer does not wipe it.
|
| **Send waits for a clean form** (amendment 15(a)): not while anything is saving, nor while a field
| holds a refused value or one not saved yet — the page says to finish the marked fields — and not
| twice. What is sent is what the page shows.
|
| After a rejection, what it marked is marked here until it is replaced — a field once it differs
| from what was sent, a paper once a new file is under its type (§1.2). A mark on a document type no
| longer offered stops counting (§3.1), so it is not shown.
*/

type Props = {
    page: CompanyPage;
    draft: CompanyDraftData;
    /** The application the rejection decided, whose flags and requests this draft answers. */
    lastSent: CompanyApplicationData | null;
    /** An approved company changing its details: said at the top and above Send (§1.1). */
    changing: boolean;
};

/** Where one field stands: waiting to be saved, being saved, or refused. */
type Standing = 'unsaved' | 'saving' | 'refused';

type Changes = {
    /** Queues one change; `start` sends it and calls `finish` once it has an answer. */
    queue: (start: (finish: () => void) => void) => void;
    /** A field says where it stands, or null once it holds what the server holds. */
    report: (field: string, standing: Standing | null) => void;
};

const ChangesContext = createContext<Changes | null>(null);

function useChanges(): Changes {
    const changes = useContext(ChangesContext);

    if (changes === null) {
        throw new Error('A company form field is outside its form.');
    }

    return changes;
}

const DETAILS = ['cr_number', 'tax_number', 'address'] as const;
const OTHER = 'other';
const ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
const MEGABYTE = 1048576;

export function CompanyForm({ page, draft, lastSent, changing }: Props) {
    const t = useTranslator();
    const link = useLink();
    const locale = useLocale();
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    const [standings, setStandings] = useState<Record<string, Standing>>({});
    const [waiting, setWaiting] = useState(0);
    const [sending, setSending] = useState(false);
    const jobs = useRef<(() => void)[]>([]);
    const running = useRef(false);

    const next = useCallback(() => {
        const job = jobs.current.shift();

        running.current = job !== undefined;
        job?.();
    }, []);

    const changes = useMemo<Changes>(
        () => ({
            queue: (start) => {
                setWaiting((count) => count + 1);
                jobs.current.push(() =>
                    start(() => {
                        setWaiting((count) => count - 1);
                        next();
                    }),
                );

                if (!running.current) {
                    next();
                }
            },
            report: (field, standing) =>
                setStandings((current) => {
                    if ((current[field] ?? null) === standing) {
                        return current;
                    }

                    const updated = { ...current };

                    if (standing === null) {
                        delete updated[field];
                    } else {
                        updated[field] = standing;
                    }

                    return updated;
                }),
        }),
        [next],
    );

    const flagged = (field: string): boolean => draft.flags.some((flag) => flag.field === field);
    const sentValue = (field: string): string | null => {
        const values = lastSent?.values;

        return values === undefined ? null : (fieldValues(values)[field] ?? null);
    };
    // Replaced when it differs, exactly after trimming, from what the rejection saw (§3.1).
    const stillFlagged = (field: string): boolean =>
        flagged(field) && (fieldValues(draft.values)[field] ?? '').trim() === (sentValue(field) ?? '').trim();

    const required = page.documentTypes.filter((type) => type.required && !type.greyed);
    const held = (typeId: string): CompanyFileData | undefined =>
        draft.documents.find((document) => document.documentTypeId === typeId);
    const done = required.filter((type) => held(type.id) !== undefined).length;
    // A held paper under a type no longer on the list: it must be removed before sending.
    const orphans = draft.documents.filter((document) => !page.documentTypes.some((type) => type.id === document.documentTypeId));
    const unfinished = Object.keys(standings).length > 0;
    const blocked = done < required.length || unfinished || waiting > 0 || sending;

    return (
        <ChangesContext.Provider value={changes}>
            <div className="grid gap-6" data-test="company-form">
                {changing ? <Warning /> : null}

                <Card title={t('b2b::company.section.details')} hint={t('b2b::company.section.details_hint')} test="details">
                    <div className="grid gap-4">
                        <SavedText field="name" label={t('b2b::company.field.name')} saved={draft.values.name} flagged={stillFlagged('name')} />
                        <TypeChoice options={page.companyTypes} draft={draft} flagged={stillFlagged('company_type')} locale={locale} />
                        {DETAILS.map((field) => (
                            <SavedText
                                key={field}
                                field={field}
                                label={t(`b2b::company.field.${field}`)}
                                saved={fieldValues(draft.values)[field] ?? null}
                                flagged={stillFlagged(field)}
                                multiline={field === 'address'}
                                figures={field !== 'address'}
                            />
                        ))}
                    </div>
                </Card>

                <Card
                    title={t('b2b::company.section.documents')}
                    hint={t('b2b::company.documents_hint', { size: Math.round(page.maxFileBytes / MEGABYTE) })}
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
                                    maxBytes={page.maxFileBytes}
                                />
                            ))}
                        </ul>
                    </Card>
                ) : null}

                <Card title={t('b2b::company.section.note')} test="note">
                    <SavedText field="note" label={t('b2b::company.field.note')} saved={draft.values.note} multiline />
                </Card>

                <div className="grid gap-3">
                    {changing ? <Warning /> : null}

                    {unfinished ? (
                        <p role="status" className="text-sm text-bad" data-test="send-blocked">
                            {t('b2b::company.send_blocked')}
                        </p>
                    ) : null}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            data-test="send"
                            disabled={blocked}
                            onClick={() => {
                                setSending(true);
                                router.post(link('storefront.company.send'), {}, { preserveScroll: true, onFinish: () => setSending(false) });
                            }}
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
        </ChangesContext.Provider>
    );
}

/** A draft's values under the keys the form and the flags use. */
function fieldValues(values: CompanyDraftData['values']): Record<string, string | null> {
    return {
        name: values.name,
        company_type: values.companyTypeId ?? values.companyTypeOther,
        cr_number: values.crNumber,
        tax_number: values.taxNumber,
        address: values.address,
        note: values.note,
    };
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
    const [discarding, setDiscarding] = useState(false);

    return (
        <div className="grid gap-3 rounded-lg border border-bad/30 bg-bad-soft p-4" data-test="discard-confirmation">
            <p className="text-sm text-ink">{t('b2b::company.discard_confirm')}</p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    data-test="discard-confirm"
                    disabled={discarding}
                    onClick={() => {
                        setDiscarding(true);
                        router.post(link('storefront.company.discard'), {}, { onFinish: () => setDiscarding(false) });
                    }}
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
 * One change of the draft, in its turn in the queue. The page comes back with the draft as it now
 * stands; the refusal, if any, is this change's own, kept by the caller.
 */
function useSend(): (url: string, data: Record<string, string | File | null>, done: (refusal: string | null) => void, errorKey: string) => void {
    const { queue } = useChanges();

    return (url, data, done, errorKey) =>
        queue((finish) => {
            let refusal: string | null = null;

            router.post(url, data, {
                preserveScroll: true,
                preserveState: true,
                forceFormData: Object.values(data).some((value) => value instanceof File),
                onError: (errors) => {
                    refusal = errors[errorKey] ?? errors.form ?? Object.values(errors)[0] ?? null;
                },
                onFinish: () => {
                    done(refusal);
                    finish();
                },
            });
        });
}

/** A line under a field that is always there, so "Saving…" appearing never moves what is below. */
function Progress({ id, saving }: { id: string; saving: boolean }) {
    const t = useTranslator();

    return (
        <p id={id} role="status" aria-live="polite" className="mt-1 min-h-4 text-xs text-ink-muted">
            {saving ? t('b2b::company.saving') : ''}
        </p>
    );
}

type SavedTextProps = {
    field: string;
    label: string;
    saved: string | null;
    flagged?: boolean;
    multiline?: boolean;
    figures?: boolean;
};

/** A text field that saves itself when the person leaves it — only when it changed. */
function SavedText({ field, label, saved, flagged = false, multiline = false, figures = false }: SavedTextProps) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const [value, setValue] = useState(saved ?? '');
    const [refusal, setRefusal] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // The draft as the server now holds it, whenever that changes — not on every re-render, which
    // would throw away a refused value the person is still correcting.
    useEffect(() => setValue(saved ?? ''), [saved]);

    const unsaved = value !== (saved ?? '');

    useEffect(() => {
        report(field, saving ? 'saving' : refusal !== null ? 'refused' : unsaved ? 'unsaved' : null);
    }, [report, field, saving, refusal, unsaved]);

    // A field that leaves the page leaves nothing behind for Send to wait on.
    useEffect(() => () => report(field, null), [report, field]);

    const leave = () => {
        if (!unsaved) {
            return;
        }

        setSaving(true);
        send(
            link('storefront.company.save'),
            { [field]: value.trim() === '' ? null : value },
            (refused) => {
                setRefusal(refused);
                setSaving(false);
            },
            field,
        );
    };

    const id = `company-${field}`;
    const common = {
        id,
        name: field,
        value,
        'aria-invalid': refusal !== null ? true : undefined,
        'aria-describedby': `${id}-progress${refusal !== null ? ` ${id}-error` : ''}`,
        'data-test': `field-${field}`,
        dir: figures ? ('ltr' as const) : undefined,
        onBlur: leave,
    };

    return (
        <Field id={id} label={label} error={refusal ?? undefined}>
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
            {flagged ? (
                <p className="mt-1 text-xs text-bad" data-test="flagged">
                    {t('b2b::company.flagged')}
                </p>
            ) : null}
            <Progress id={`${id}-progress`} saving={saving} />
        </Field>
    );
}

/**
 * The company type: one of the home store's, or "Other" and the company's own words (§1.3). A
 * greyed type is shown and cannot be chosen. Choosing "Other" saves nothing until the words are
 * left — the type chosen before stays until then, and Send waits.
 */
function TypeChoice({
    options,
    draft,
    flagged,
    locale,
}: {
    options: CompanyTypeOptionData[];
    draft: CompanyDraftData;
    flagged: boolean;
    locale: 'ar' | 'en';
}) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const savedChoice = draft.values.companyTypeId ?? (draft.values.companyTypeOther !== null ? OTHER : '');
    const savedWords = draft.values.companyTypeOther ?? '';
    const [choice, setChoice] = useState(savedChoice);
    const [words, setWords] = useState(savedWords);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => setChoice(savedChoice), [savedChoice]);
    useEffect(() => setWords(savedWords), [savedWords]);

    const unsaved = choice !== savedChoice || (choice === OTHER && words !== savedWords);

    useEffect(() => {
        report('company_type', saving ? 'saving' : refusal !== null ? 'refused' : unsaved ? 'unsaved' : null);
    }, [report, saving, refusal, unsaved]);

    useEffect(() => () => report('company_type', null), [report]);

    const save = (fields: Record<string, string | null>) => {
        setSaving(true);
        send(
            link('storefront.company.save'),
            fields,
            (refused) => {
                setRefusal(refused);
                setSaving(false);
            },
            'company_type',
        );
    };

    const describedBy = `company-type-progress${refusal !== null ? ' company-type-error' : ''}`;

    return (
        <div className="grid gap-3">
            <Field id="company-type" label={t('b2b::company.field.company_type')} error={choice === OTHER ? undefined : (refusal ?? undefined)}>
                <select
                    id="company-type"
                    data-test="field-company_type"
                    value={choice}
                    aria-invalid={refusal !== null && choice !== OTHER ? true : undefined}
                    aria-describedby={describedBy}
                    onChange={(event) => {
                        const chosen = event.target.value;
                        setChoice(chosen);

                        if (chosen !== OTHER) {
                            save({ company_type_id: chosen === '' ? null : chosen, company_type_other: null });
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
                {flagged ? (
                    <p className="mt-1 text-xs text-bad" data-test="flagged">
                        {t('b2b::company.flagged')}
                    </p>
                ) : null}
                <Progress id="company-type-progress" saving={saving && choice !== OTHER} />
            </Field>

            {choice === OTHER ? (
                <Field id="company-type-other" label={t('b2b::company.field.other_words')} error={refusal ?? undefined}>
                    <Input
                        id="company-type-other"
                        data-test="field-company_type_other"
                        value={words}
                        aria-invalid={refusal !== null ? true : undefined}
                        aria-describedby={`company-type-other-progress${refusal !== null ? ' company-type-other-error' : ''}`}
                        onChange={(event) => setWords(event.target.value)}
                        onBlur={() => {
                            if (unsaved && words.trim() !== '') {
                                save({ company_type_id: null, company_type_other: words });
                            }
                        }}
                    />
                    <Progress id="company-type-other-progress" saving={saving} />
                </Field>
            ) : null}
        </div>
    );
}

/** A file input the person never sees: the button opens it, and choosing a file queues it. */
function useFilePicker(
    url: string,
    maxBytes: number,
    errorKey: string,
): { input: RefObject<HTMLInputElement | null>; pick: () => void; onChange: (event: ChangeEvent<HTMLInputElement>) => void; sending: boolean; refusal: string | null } {
    const t = useTranslator();
    const send = useSend();
    const input = useRef<HTMLInputElement | null>(null);
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);

    return {
        input,
        pick: () => input.current?.click(),
        sending,
        refusal,
        onChange: (event) => {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (file === undefined) {
                return;
            }

            // Said at once, rather than after sending ten megabytes the server will refuse.
            if (file.size > maxBytes) {
                setRefusal(t('b2b::company.too_large', { size: Math.round(maxBytes / MEGABYTE) }));

                return;
            }

            setRefusal(null);
            setSending(true);
            send(
                url,
                { file },
                (refused) => {
                    setRefusal(refused);
                    setSending(false);
                },
                errorKey,
            );
        },
    };
}

function DocumentRow({
    type,
    file,
    flagged,
    sentMediaId,
    maxBytes,
    locale,
}: {
    type: CompanyTypeOptionData;
    file: CompanyFileData | undefined;
    flagged: boolean;
    sentMediaId: string | null;
    maxBytes: number;
    locale: 'ar' | 'en';
}) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const errorKey = `documents.${type.id}`;
    const picker = useFilePicker(link('storefront.company.attach', { type: type.id }), maxBytes, errorKey);
    const [removal, setRemoval] = useState<string | null>(null);
    const name = nameOf(type, locale);
    // Replaced once the file under the type is not the one the rejection saw; a type no longer
    // offered stops counting (§3.1).
    const stillFlagged = flagged && !type.greyed && (file === undefined || file.mediaId === sentMediaId);
    const refusal = picker.refusal ?? removal;

    return (
        <li className="grid gap-2 rounded-md border border-line p-3" data-test={`document-${type.id}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium text-ink">{name}</span>
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
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={picker.sending}
                            aria-label={`${file === undefined ? t('b2b::company.choose_file') : t('b2b::company.replace')}: ${name}`}
                            onClick={picker.pick}
                        >
                            {picker.sending ? t('b2b::company.saving') : file === undefined ? t('b2b::company.choose_file') : t('b2b::company.replace')}
                        </Button>
                    </>
                )}
                {file !== undefined ? (
                    <>
                        <Button asChild variant="ghost" size="sm">
                            <a
                                href={link('storefront.company.file', { file: file.mediaId })}
                                target="_blank"
                                rel="noreferrer"
                                aria-label={`${t('b2b::company.open')}: ${name}`}
                            >
                                {t('b2b::company.open')}
                            </a>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test={`remove-${type.id}`}
                            aria-label={`${t('b2b::company.remove')}: ${name}`}
                            onClick={() => send(link('storefront.company.detach', { type: type.id }), {}, setRemoval, errorKey)}
                        >
                            {t('b2b::company.remove')}
                        </Button>
                    </>
                ) : null}
            </div>

            {file?.noLongerAccepted ? <p className="text-xs text-bad">{t('b2b::company.no_longer_accepted')}</p> : null}
            {stillFlagged ? (
                <p className="text-xs text-bad" data-test="flagged">
                    {t('b2b::company.flagged_document')}
                </p>
            ) : null}
            {refusal !== null ? (
                <p role="alert" className="text-xs text-bad">
                    {refusal}
                </p>
            ) : null}
        </li>
    );
}

function RequestRow({ request, answer, maxBytes }: { request: CompanyRequestData; answer: CompanyAnswerData | null; maxBytes: number }) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const url = link('storefront.company.answer', { answered: request.id });
    const errorKey = `answers.${request.id}`;
    const picker = useFilePicker(url, maxBytes, errorKey);
    const savedText = answer?.text ?? '';
    const [text, setText] = useState(savedText);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const labelId = `request-${request.id}-label`;
    const errorId = `request-${request.id}-error`;

    useEffect(() => setText(savedText), [savedText]);

    const unsaved = request.kind === 'TEXT' && text !== savedText;

    useEffect(() => {
        report(`answers.${request.id}`, saving ? 'saving' : refusal !== null ? 'refused' : unsaved ? 'unsaved' : null);
    }, [report, request.id, saving, refusal, unsaved]);

    useEffect(() => () => report(`answers.${request.id}`, null), [report, request.id]);

    const shown = picker.refusal ?? refusal;

    return (
        <li className="grid gap-2" data-test={`request-${request.id}`}>
            <p id={labelId} className="text-sm font-medium text-ink">
                {request.label}
            </p>

            {request.kind === 'TEXT' ? (
                <>
                    <textarea
                        aria-labelledby={labelId}
                        aria-invalid={shown !== null ? true : undefined}
                        aria-describedby={`request-${request.id}-progress${shown !== null ? ` ${errorId}` : ''}`}
                        rows={3}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        onBlur={() => {
                            if (!unsaved) {
                                return;
                            }

                            setSaving(true);
                            const empty = text.trim() === '';
                            send(
                                empty ? link('storefront.company.unanswer', { answered: request.id }) : url,
                                empty ? {} : { text },
                                (refused) => {
                                    setRefusal(refused);
                                    setSaving(false);
                                },
                                errorKey,
                            );
                        }}
                        className="w-full rounded-md border border-line-strong bg-surface p-3 text-sm text-ink"
                    />
                    <Progress id={`request-${request.id}-progress`} saving={saving} />
                </>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <input ref={picker.input} type="file" accept={ACCEPT} className="hidden" onChange={picker.onChange} />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={picker.sending}
                        aria-label={`${answer?.mediaId ? t('b2b::company.replace') : t('b2b::company.choose_file')}: ${request.label}`}
                        onClick={picker.pick}
                    >
                        {picker.sending ? t('b2b::company.saving') : answer?.mediaId ? t('b2b::company.replace') : t('b2b::company.choose_file')}
                    </Button>
                    {answer?.mediaId ? (
                        <>
                            <Button asChild variant="ghost" size="sm">
                                <a
                                    href={link('storefront.company.file', { file: answer.mediaId })}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-label={`${t('b2b::company.open')}: ${request.label}`}
                                >
                                    {t('b2b::company.open')}
                                </a>
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`${t('b2b::company.remove')}: ${request.label}`}
                                onClick={() => send(link('storefront.company.unanswer', { answered: request.id }), {}, setRefusal, errorKey)}
                            >
                                {t('b2b::company.remove')}
                            </Button>
                        </>
                    ) : null}
                </div>
            )}

            {shown !== null ? (
                <p id={errorId} role="alert" className="text-xs text-bad">
                    {shown}
                </p>
            ) : null}
        </li>
    );
}
