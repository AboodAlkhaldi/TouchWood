import { type ChangeEvent, createContext, type RefObject, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { isolate } from '@/lib/bidi';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import type {
    CompanyAnswerData,
    CompanyApplicationData,
    CompanyDraftData,
    CompanyFieldRuleData,
    CompanyFileData,
    CompanyPage,
    CompanyRequestData,
    CompanyTypeOptionData,
} from '@/types/generated/Modules/B2B/Presentation/Http/Resource';
import { AddressPicker, border, check, FieldState, type Look } from './fields';
import { Card, nameOf, useLocale, when } from './parts';

/*
| The company form (b2b.md §4.5, amendments 14(a), 15(a) and 16): company details, documents, what
| the last rejection asked for, and a note — then Send.
|
| **It saves itself.** A field is saved when the person leaves it, a file the moment it is chosen,
| an address the moment it is picked, so nothing is lost by walking away. Each save sends that one
| field; the rest of the draft keeps what it holds.
|
| **Each field says where it stands** (amendment 16(a)): yellow while it is not valid — and then it
| is never sent —, "Saving…", green "Saved" once the server holds it, red with the server's reason
| if it refuses. The rules are the server's own (CompanyPage::formRules).
|
| **One change at a time, in the order made.** Every save, upload and removal waits in one queue:
| a page request started while another runs would cancel it, and a save, a refusal or a paper would
| be lost without a word (the review of step 6). A save never stops the person filling the others.
|
| **Send is inactive until everything is complete** (amendment 16(d)), with what is missing listed
| beside it — and not while anything is saving, nor while a field is not saved or not valid
| (amendment 15(a)), nor twice. What is sent is what the page shows; the server checks it again.
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

/** Where one field stands while it keeps Send waiting. */
type Standing = 'invalid' | 'unsaved' | 'saving' | 'refused';

/** A refusal belongs to the value refused: changing the value takes it away. */
type Refusal = { value: string; message: string };

type Changes = {
    /** Queues one change; `start` sends it and calls `finish` once it has an answer. */
    queue: (start: (finish: () => void) => void) => void;
    /** A field says where it stands, or null once nothing about it keeps Send waiting. */
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

const NUMBERS = ['cr_number', 'tax_number'] as const;
const OTHER = 'other';
const ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';
const MEGABYTE = 1048576;

type Translate = (key: string, values?: Record<string, string | number>) => string;

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

    const stillFlagged = (field: string): boolean => fieldStillFlagged(field, draft, lastSent);
    const required = page.documentTypes.filter((type) => type.required && !type.greyed);
    const held = (typeId: string): CompanyFileData | undefined =>
        draft.documents.find((document) => document.documentTypeId === typeId);
    const done = required.filter((type) => held(type.id) !== undefined).length;
    // A held paper under a type no longer on the list: it must be removed before sending.
    const orphans = draft.documents.filter((document) => !page.documentTypes.some((type) => type.id === document.documentTypeId));
    const missing = [
        ...missingItems(page, draft, lastSent, t),
        ...(Object.keys(standings).length > 0 ? [t('b2b::company.missing_marked')] : []),
    ];
    const blocked = missing.length > 0 || waiting > 0 || sending;

    return (
        <ChangesContext.Provider value={changes}>
            <div className="grid gap-6" data-test="company-form">
                {changing ? <Warning /> : null}

                <Card title={t('b2b::company.section.details')} hint={t('b2b::company.section.details_hint')} test="details">
                    <div className="grid gap-4">
                        <SavedText
                            field="name"
                            label={t('b2b::company.field.name')}
                            saved={draft.values.name}
                            rule={page.formRules.name}
                            required
                            flagged={stillFlagged('name')}
                        />
                        <TypeChoice options={page.companyTypes} draft={draft} rule={page.formRules.company_type_other} flagged={stillFlagged('company_type')} locale={locale} />
                        {NUMBERS.map((field) => (
                            <SavedText
                                key={field}
                                field={field}
                                label={t(`b2b::company.field.${field}`)}
                                saved={fieldValues(draft.values)[field] ?? null}
                                rule={page.formRules[field]}
                                required
                                flagged={stillFlagged(field)}
                                figures
                            />
                        ))}
                        <DraftAddress page={page} draft={draft} flagged={stillFlagged('address')} locale={locale} />
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
                                others={draft.documents.filter((document) => document.documentTypeId !== type.id)}
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
                                others={[]}
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
                                    rule={page.formRules.answer}
                                    maxBytes={page.maxFileBytes}
                                />
                            ))}
                        </ul>
                    </Card>
                ) : null}

                <Card title={t('b2b::company.section.note')} test="note">
                    <SavedText field="note" label={t('b2b::company.field.note')} saved={draft.values.note} rule={page.formRules.note} required={false} multiline />
                </Card>

                <div className="grid gap-3">
                    {changing ? <Warning /> : null}

                    {missing.length > 0 ? (
                        <p role="status" className="text-sm text-ink-muted" data-test="send-missing">
                            {t('b2b::company.missing', { items: missing.join(t('b2b::company.separator')) })}
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
                            {t('b2b::company.send')}
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

/** A value as the server keeps it: line breaks as one, and nothing at either end. */
function normal(value: string): string {
    return value.replace(/\r\n?/g, '\n').trim();
}

/** Marked by the last rejection and not replaced: the same, after trimming, as what it saw (§3.1). */
function fieldStillFlagged(field: string, draft: CompanyDraftData, lastSent: CompanyApplicationData | null): boolean {
    const sent = lastSent === null ? null : (fieldValues(lastSent.values)[field] ?? null);

    return draft.flags.some((flag) => flag.field === field) && (fieldValues(draft.values)[field] ?? '').trim() === (sent ?? '').trim();
}

/**
 * What the draft still needs before it can be sent (amendment 16(d)) — the same list the server
 * refuses on (§1.2): every value, a type still accepted, every required paper, nothing no longer
 * accepted, every marked item replaced, every request answered.
 */
export function missingItems(page: CompanyPage, draft: CompanyDraftData, lastSent: CompanyApplicationData | null, t: Translate): string[] {
    const values = fieldValues(draft.values);
    const items = (['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const)
        .filter((field) => values[field] === null || (field === 'company_type' && draft.typeNoLongerAccepted))
        .map((field) => t(`b2b::company.field.${field}`));

    const documents = page.documentTypes.filter(
        (type) => type.required && !type.greyed && !draft.documents.some((document) => document.documentTypeId === type.id),
    ).length;
    const notAccepted = draft.documents.filter((document) => document.noLongerAccepted).length;
    const flagged = draft.flags.filter((flag) => {
        if (flag.documentTypeId === null) {
            return flag.field !== null && fieldStillFlagged(flag.field, draft, lastSent);
        }

        // A mark on a type no longer offered stops counting (§3.1).
        const type = page.documentTypes.find((offered) => offered.id === flag.documentTypeId);
        const file = draft.documents.find((document) => document.documentTypeId === flag.documentTypeId);
        const sent = lastSent?.documents.find((document) => document.documentTypeId === flag.documentTypeId)?.mediaId ?? null;

        return type !== undefined && !type.greyed && (file === undefined || file.mediaId === sent);
    }).length;
    const answers = draft.requests.filter((request) => !draft.answers.some((answer) => answer.requestId === request.id)).length;

    return [
        ...items,
        ...(documents > 0 ? [t('b2b::company.missing_documents', { count: documents })] : []),
        ...(notAccepted > 0 ? [t('b2b::company.missing_not_accepted', { count: notAccepted })] : []),
        ...(flagged > 0 ? [t('b2b::company.missing_flagged', { count: flagged })] : []),
        ...(answers > 0 ? [t('b2b::company.missing_answers', { count: answers })] : []),
    ];
}

/** Where a field stands, the strongest first. */
function lookOf({ saving, refused, invalid, unsaved, saved }: { saving: boolean; refused: boolean; invalid: boolean; unsaved: boolean; saved: boolean }): Look {
    if (saving) {
        return 'saving';
    }

    if (refused) {
        return 'refused';
    }

    if (invalid) {
        return 'invalid';
    }

    if (unsaved) {
        return 'unsaved';
    }

    return saved ? 'saved' : 'idle';
}

/** What keeps Send waiting, from how a field looks. */
function standingOf(look: Look): Standing | null {
    return look === 'saving' || look === 'refused' || look === 'invalid' || look === 'unsaved' ? look : null;
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

type SavedTextProps = {
    field: string;
    label: string;
    saved: string | null;
    rule: CompanyFieldRuleData | undefined;
    required: boolean;
    flagged?: boolean;
    multiline?: boolean;
    figures?: boolean;
};

/**
 * A text field that saves itself when the person leaves it — only when it changed, and never while
 * it is not valid. An empty field nobody has left yet is not marked: it is listed beside Send.
 */
function SavedText({ field, label, saved, rule, required, flagged = false, multiline = false, figures = false }: SavedTextProps) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const [value, setValue] = useState(saved ?? '');
    const [left, setLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [saving, setSaving] = useState(false);

    // The draft as the server now holds it, whenever that changes — not on every re-render, which
    // would throw away a value the person is still correcting.
    useEffect(() => setValue(saved ?? ''), [saved]);

    const unsaved = normal(value) !== normal(saved ?? '');
    const problem = check(value, rule, required, t);
    const look = lookOf({
        saving,
        refused: refusal !== null && refusal.value === value,
        invalid: problem !== null && (left || value.trim() !== '' || (saved ?? '') !== ''),
        unsaved,
        saved: (saved ?? '') !== '',
    });

    useEffect(() => report(field, standingOf(look)), [report, field, look]);

    // A field that leaves the page leaves nothing behind for Send to wait on.
    useEffect(() => () => report(field, null), [report, field]);

    const leave = () => {
        setLeft(true);

        // Never sent while it is not valid (amendment 16(a)): it stays yellow until it is.
        if (!unsaved || problem !== null) {
            return;
        }

        const sent = value;
        setSaving(true);
        send(
            link('storefront.company.save'),
            { [field]: normal(sent) === '' ? null : sent },
            (refused) => {
                setRefusal(refused === null ? null : { value: sent, message: refused });
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
        'aria-invalid': look === 'invalid' || look === 'refused' ? true : undefined,
        'aria-describedby': `${id}-state`,
        'data-test': `field-${field}`,
        'data-look': look,
        dir: figures ? ('ltr' as const) : undefined,
        onBlur: leave,
    };

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className="text-ink">
                {label}
            </Label>
            {multiline ? (
                <textarea
                    {...common}
                    rows={3}
                    onChange={(event) => setValue(event.target.value)}
                    className={['w-full rounded-md border bg-surface p-3 text-sm text-ink', border(look)].join(' ')}
                />
            ) : (
                <Input {...common} className={border(look)} onChange={(event) => setValue(event.target.value)} />
            )}
            {flagged ? (
                <p className="text-xs text-bad" data-test="flagged">
                    {t('b2b::company.flagged')}
                </p>
            ) : null}
            <FieldState id={`${id}-state`} look={look} message={look === 'refused' ? (refusal?.message ?? null) : problem} />
        </div>
    );
}

/**
 * The company type: one of the home store's, or "Other" and the company's own words (§1.3). A
 * greyed type is shown and cannot be chosen. Choosing "Other" saves nothing until the words are
 * valid and left — the type chosen before stays until then, and Send waits. Going back to
 * "Choose…" is not a choice, and is not sent.
 */
function TypeChoice({
    options,
    draft,
    rule,
    flagged,
    locale,
}: {
    options: CompanyTypeOptionData[];
    draft: CompanyDraftData;
    rule: CompanyFieldRuleData | undefined;
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
    const [left, setLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => setChoice(savedChoice), [savedChoice]);
    useEffect(() => setWords(savedWords), [savedWords]);

    const other = choice === OTHER;
    // What the page would send, so a refusal is known to be about what is on screen.
    const shown = other ? `${OTHER}:${words}` : choice;
    const refused = refusal !== null && refusal.value === shown;
    const unsaved = choice !== savedChoice || (other && normal(words) !== normal(savedWords));
    const choiceProblem = choice === '' ? t('b2b::company.check.required') : null;
    const wordsProblem = other ? check(words, rule, true, t) : null;

    const selectLook = other
        ? 'idle'
        : lookOf({ saving, refused, invalid: choiceProblem !== null && (left || savedChoice !== ''), unsaved, saved: savedChoice !== '' });
    const wordsLook = lookOf({
        saving,
        refused,
        invalid: wordsProblem !== null && (left || words.trim() !== '' || savedWords !== ''),
        unsaved,
        saved: savedChoice === OTHER,
    });

    useEffect(() => report('company_type', standingOf(other ? wordsLook : selectLook)), [report, other, wordsLook, selectLook]);

    useEffect(() => () => report('company_type', null), [report]);

    const save = (fields: Record<string, string | null>, sent: string) => {
        setSaving(true);
        send(
            link('storefront.company.save'),
            fields,
            (refusedWith) => {
                setRefusal(refusedWith === null ? null : { value: sent, message: refusedWith });
                setSaving(false);
            },
            'company_type',
        );
    };

    return (
        <div className="grid gap-3">
            <div className="grid gap-1.5">
                <Label htmlFor="company-type" className="text-ink">
                    {t('b2b::company.field.company_type')}
                </Label>
                <select
                    id="company-type"
                    data-test="field-company_type"
                    data-look={selectLook}
                    value={choice}
                    aria-invalid={selectLook === 'invalid' || selectLook === 'refused' ? true : undefined}
                    aria-describedby="company-type-state"
                    onChange={(event) => {
                        const chosen = event.target.value;
                        setChoice(chosen);
                        setLeft(true);

                        if (chosen !== OTHER && chosen !== '') {
                            save({ company_type_id: chosen, company_type_other: null }, chosen);
                        }
                    }}
                    className={['h-9 w-full rounded-md border bg-surface px-3 text-sm text-ink', border(selectLook)].join(' ')}
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
                    <p className="text-xs text-bad" data-test="type-no-longer">
                        {t('b2b::company.type_no_longer')}
                    </p>
                ) : null}
                {flagged ? (
                    <p className="text-xs text-bad" data-test="flagged">
                        {t('b2b::company.flagged')}
                    </p>
                ) : null}
                <FieldState id="company-type-state" look={selectLook} message={selectLook === 'refused' ? (refusal?.message ?? null) : choiceProblem} />
            </div>

            {other ? (
                <div className="grid gap-1.5">
                    <Label htmlFor="company-type-other" className="text-ink">
                        {t('b2b::company.field.other_words')}
                    </Label>
                    <Input
                        id="company-type-other"
                        data-test="field-company_type_other"
                        data-look={wordsLook}
                        value={words}
                        className={border(wordsLook)}
                        aria-invalid={wordsLook === 'invalid' || wordsLook === 'refused' ? true : undefined}
                        aria-describedby="company-type-other-state"
                        onChange={(event) => setWords(event.target.value)}
                        onBlur={() => {
                            setLeft(true);

                            if (unsaved && wordsProblem === null) {
                                save({ company_type_id: null, company_type_other: words }, `${OTHER}:${words}`);
                            }
                        }}
                    />
                    <FieldState
                        id="company-type-other-state"
                        look={wordsLook}
                        message={wordsLook === 'refused' ? (refusal?.message ?? null) : wordsProblem}
                    />
                </div>
            ) : null}
        </div>
    );
}

/**
 * The address, picked from the account's saved addresses and saved the moment it is picked
 * (amendment 16(f)). A pick the server refuses leaves the one saved before, and says why in red.
 */
function DraftAddress({ page, draft, flagged, locale }: { page: CompanyPage; draft: CompanyDraftData; flagged: boolean; locale: 'ar' | 'en' }) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const [picking, setPicking] = useState<string | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const look = lookOf({ saving: picking !== null, refused: refusal !== null, invalid: false, unsaved: false, saved: draft.values.address !== null });

    // Only a save under way keeps Send waiting: a refused pick changes nothing that was saved.
    useEffect(() => report('address', picking !== null ? 'saving' : null), [report, picking]);

    useEffect(() => () => report('address', null), [report]);

    return (
        <div className="grid gap-1.5">
            <AddressPicker
                addresses={page.savedAddresses}
                pickedId={picking ?? draft.values.addressId}
                kept={draft.values.address}
                look={look}
                message={refusal}
                disabled={picking !== null}
                locale={locale}
                onPick={(addressId) => {
                    setPicking(addressId);
                    setRefusal(null);
                    send(
                        link('storefront.company.save'),
                        { address_id: addressId },
                        (refused) => {
                            setRefusal(refused);
                            setPicking(null);
                        },
                        'address',
                    );
                }}
            />
            {flagged ? (
                <p className="text-xs text-bad" data-test="flagged">
                    {t('b2b::company.flagged')}
                </p>
            ) : null}
        </div>
    );
}

/** A file input the person never sees: the button opens it, and choosing a file queues it. */
function useFilePicker(
    url: string,
    maxBytes: number,
    errorKey: string,
    precheck: (file: File) => string | null = () => null,
): {
    input: RefObject<HTMLInputElement | null>;
    pick: () => void;
    onChange: (event: ChangeEvent<HTMLInputElement>) => void;
    sending: boolean;
    refusal: string | null;
    warning: string | null;
} {
    const t = useTranslator();
    const send = useSend();
    const input = useRef<HTMLInputElement | null>(null);
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [warning, setWarning] = useState<string | null>(null);

    return {
        input,
        pick: () => input.current?.click(),
        sending,
        refusal,
        warning,
        onChange: (event) => {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (file === undefined) {
                return;
            }

            setRefusal(null);

            // Said at once, rather than after sending ten megabytes the server will refuse.
            if (file.size > maxBytes) {
                setWarning(t('b2b::company.too_large', { size: Math.round(maxBytes / MEGABYTE) }));

                return;
            }

            const problem = precheck(file);
            setWarning(problem);

            if (problem !== null) {
                return;
            }

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
    others,
    flagged,
    sentMediaId,
    maxBytes,
    locale,
}: {
    type: CompanyTypeOptionData;
    file: CompanyFileData | undefined;
    /** The papers under the draft's other document types: none of them may be the same file. */
    others: CompanyFileData[];
    flagged: boolean;
    sentMediaId: string | null;
    maxBytes: number;
    locale: 'ar' | 'en';
}) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const errorKey = `documents.${type.id}`;
    // The same file in two sections is a mistake (amendment 16(c)): by its name, exactly, before
    // anything is uploaded. The section's own file may be replaced by one of the same name.
    const picker = useFilePicker(link('storefront.company.attach', { type: type.id }), maxBytes, errorKey, (chosen) => {
        const same = others.find((other) => other.fileName === chosen.name);

        return same === undefined
            ? null
            : t('b2b::company.duplicate_file', { section: nameOf({ nameAr: same.documentTypeNameAr ?? '', nameEn: same.documentTypeNameEn ?? '' }, locale) });
    });
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
            {picker.warning !== null ? (
                <p role="alert" className="text-xs text-warn" data-test="file-warning">
                    {picker.warning}
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

function RequestRow({
    request,
    answer,
    rule,
    maxBytes,
}: {
    request: CompanyRequestData;
    answer: CompanyAnswerData | null;
    rule: CompanyFieldRuleData | undefined;
    maxBytes: number;
}) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const url = link('storefront.company.answer', { answered: request.id });
    const errorKey = `answers.${request.id}`;
    const picker = useFilePicker(url, maxBytes, errorKey);
    const savedText = answer?.text ?? '';
    const [text, setText] = useState(savedText);
    const [left, setLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [removal, setRemoval] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const labelId = `request-${request.id}-label`;
    const stateId = `request-${request.id}-state`;
    const written = request.kind === 'TEXT';

    useEffect(() => setText(savedText), [savedText]);

    const problem = written ? check(text, rule, true, t) : null;
    const look = written
        ? lookOf({
              saving,
              refused: refusal !== null && refusal.value === text,
              invalid: problem !== null && (left || text.trim() !== '' || savedText !== ''),
              unsaved: normal(text) !== normal(savedText),
              saved: savedText !== '',
          })
        : 'idle';

    useEffect(() => report(`answers.${request.id}`, standingOf(look)), [report, request.id, look]);

    useEffect(() => () => report(`answers.${request.id}`, null), [report, request.id]);

    const shown = picker.refusal ?? removal;

    return (
        <li className="grid gap-2" data-test={`request-${request.id}`}>
            <p id={labelId} className="text-sm font-medium text-ink">
                {request.label}
            </p>

            {written ? (
                <>
                    <textarea
                        aria-labelledby={labelId}
                        aria-invalid={look === 'invalid' || look === 'refused' ? true : undefined}
                        aria-describedby={stateId}
                        data-look={look}
                        rows={3}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        onBlur={() => {
                            setLeft(true);

                            // An empty answer is no answer, and is not sent: it stays yellow (16(a)).
                            if (normal(text) === normal(savedText) || problem !== null) {
                                return;
                            }

                            const sent = text;
                            setSaving(true);
                            send(
                                url,
                                { text: sent },
                                (refused) => {
                                    setRefusal(refused === null ? null : { value: sent, message: refused });
                                    setSaving(false);
                                },
                                errorKey,
                            );
                        }}
                        className={['w-full rounded-md border bg-surface p-3 text-sm text-ink', border(look)].join(' ')}
                    />
                    <FieldState id={stateId} look={look} message={look === 'refused' ? (refusal?.message ?? null) : problem} />
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
                                onClick={() => send(link('storefront.company.unanswer', { answered: request.id }), {}, setRemoval, errorKey)}
                            >
                                {t('b2b::company.remove')}
                            </Button>
                        </>
                    ) : null}
                </div>
            )}

            {picker.warning !== null ? (
                <p role="alert" className="text-xs text-warn">
                    {picker.warning}
                </p>
            ) : null}
            {shown !== null ? (
                <p role="alert" className="text-xs text-bad">
                    {shown}
                </p>
            ) : null}
        </li>
    );
}
