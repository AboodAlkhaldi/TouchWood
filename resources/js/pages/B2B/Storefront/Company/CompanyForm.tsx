import { type ChangeEvent, createContext, type ReactNode, type RefObject, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ExternalLink, MoreHorizontal } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { DialogError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { MiddleTruncate } from '@/components/geist-only/MiddleTruncate';
import { Time } from '@/components/Time';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupInput, InputGroupTextarea } from '@/components/ui/input-group';
import { Item, ItemActions, ItemContent, ItemDescription, ItemTitle } from '@/components/ui/item';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { intlLocale } from '@/lib/digits';
import { useLink } from '@/lib/routes';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
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
import { AddressPicker, check, FieldState, type Look, mustFix, normal, ruleOf, SaveBeside, SaveMark, subjectOf } from './fields';
import { Card, MARK, nameOf, Phrase, useLocale } from './parts';

/*
| The company form (b2b.md §4.5, amendments 14(a), 15(a), 16 and 22): company details, documents,
| what the last rejection asked for, and a note - then Send.
|
| **It saves itself.** A field is saved when the person leaves it, a file the moment it is chosen,
| an address the moment it is picked, so nothing is lost by walking away. Each save sends that one
| field; the rest of the draft keeps what it holds.
|
| **Each field says where it stands** (amendment 22(a)): "Saving…" and then "✓ Saved" at its end,
| its rule in grey under it, which turns red with the reason when it must be fixed - not valid once
| left (and then never sent), refused by the server, or marked by the last decision. The rules are
| the server's own (CompanyPage::formRules).
|
| **One change at a time, in the order made.** Every save, upload and removal waits in one queue:
| a page request started while another runs would cancel it, and a save, a refusal or a paper would
| be lost without a word (the review of step 6). A save never stops the person filling the others.
|
| **Send is inactive until everything is complete** (amendment 16(d)), with what is missing listed
| beside it - and not while anything is saving, nor while a field is not saved or not valid
| (amendment 15(a)), nor twice. What is sent is what the page shows; the server checks it again.
|
| After a rejection, what it marked is marked here until it is replaced - a field once it differs
| from what was sent, a paper once a new file is under its type (§1.2). A mark on a document type no
| longer offered stops counting (§3.1), so it is not shown.
|
| On shadcn's parts with Geist's rules (frontend.md §1.11): each field is shadcn's Field around an
| input group; each paper an Item, with at most two buttons and the rest in its ⋯ menu (Geist's
| Entity); a button that sends is `loading` while it does, and one that cannot be pressed yet says
| why; discarding the draft and removing a file are confirmed in shadcn's AlertDialog; the warning
| to an approved company is one warning Note at the top of the form (owner, 2026-10-04).
*/

type Props = {
    page: CompanyPage;
    draft: CompanyDraftData;
    /** The application the rejection decided, whose flags and requests this draft answers. */
    lastSent: CompanyApplicationData | null;
    /** An approved company changing its details: said at the top of the form (§1.1). */
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
    const held = (typeId: string): CompanyFileData | undefined => draft.documents.find((document) => document.documentTypeId === typeId);
    const done = required.filter((type) => held(type.id) !== undefined).length;
    // A held paper under a type no longer on the list: it must be removed before sending.
    const orphans = draft.documents.filter((document) => !page.documentTypes.some((type) => type.id === document.documentTypeId));
    // Numbers in a sentence in the page's digits: Arabic-Indic on an Arabic page (frontend.md §1.8).
    const figure = (value: number) => new Intl.NumberFormat(intlLocale(locale)).format(value);
    const missing = [...missingItems(page, draft, lastSent, t, figure), ...(Object.keys(standings).length > 0 ? [t('b2b::company.missing_marked')] : [])];
    // Why Send cannot be pressed now, if it cannot: a save still out, or something still missing.
    // While it sends it is `loading` instead, which also stops a second press.
    const blocked = waiting > 0 ? t('b2b::company.saving_wait') : missing.length > 0 ? t('b2b::company.send_incomplete') : undefined;
    const phoneUrl = link('storefront.account', { tab: 'phone' });

    return (
        <ChangesContext.Provider value={changes}>
            <div className="grid gap-6" data-test="company-form">
                {/* What sending a change does to an approved company: one Note, at the top of the
                    form, before anything is changed (owner, 2026-10-04; §1.1, amendment 14(e)). */}
                {changing ? (
                    <Note variant="warning" data-test="change-warning">
                        {t('b2b::company.change_warning')}
                    </Note>
                ) : null}

                {/* Send waits for a confirmed phone, and says so before anything is filled in, with
                    the way to confirm it (amendment 26(a), owner 2026-10-04). A plain click leaves
                    after the saves still waiting, as Add Address does: leaving at once would cancel
                    them (17(i)); a click to open it elsewhere is left to the browser. */}
                {page.phoneConfirmed ? null : (
                    <Note variant="warning" data-test="phone-note">
                        {t('b2b::company.phone_note')}{' '}
                        <Link
                            href={phoneUrl}
                            className="font-medium underline underline-offset-4"
                            data-test="phone-link"
                            onClick={(event) => {
                                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                                    return;
                                }

                                event.preventDefault();
                                changes.queue(() => router.visit(phoneUrl));
                            }}
                        >
                            {t('b2b::company.phone_link')}
                        </Link>
                    </Note>
                )}

                <Card title={t('b2b::company.section.details')} hint={t('b2b::company.section.details_hint')} test="details">
                    <div className="grid gap-5">
                        <SavedText field="name" label={t('b2b::company.field.name')} saved={draft.values.name} rule={page.formRules.name} required flagged={stillFlagged('name')} />
                        <TypeChoice options={page.companyTypes} draft={draft} rule={page.formRules.company_type_other} flagged={stillFlagged('company_type')} locale={locale} />
                        {NUMBERS.map((field) => (
                            <SavedText
                                key={field}
                                field={field}
                                label={field === 'cr_number' ? t('b2b::company.field.cr_number') : t('b2b::company.field.tax_number')}
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

                <Card title={t('b2b::company.section.documents')} hint={t('b2b::company.documents_hint', { size: figure(Math.round(page.maxFileBytes / MEGABYTE)) })} test="documents">
                    <p className="text-copy-13 text-ink-muted" data-test="documents-done">
                        {t('b2b::company.documents_done', { done: figure(done), total: figure(required.length) })}
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
                        <ul className="grid gap-5">
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
                    {missing.length > 0 ? (
                        <p className="text-copy-14 text-ink-muted" data-test="send-missing">
                            {t('b2b::company.missing', { items: missing.join(t('b2b::company.separator')) })}
                        </p>
                    ) : null}

                    <div className="flex flex-wrap items-center gap-3">
                        <ActionButton
                            data-test="send"
                            loading={sending}
                            disabledReason={blocked}
                            onClick={() => {
                                setSending(true);
                                router.post(link('storefront.company.send'), {}, { preserveScroll: true, onFinish: () => setSending(false) });
                            }}
                        >
                            {t('b2b::company.send')}
                        </ActionButton>
                        <ActionButton
                            variant="outline"
                            data-test="discard"
                            // Not while a save is out: its page request would cancel the saves (17(i)).
                            disabledReason={waiting > 0 ? t('b2b::company.saving_wait') : undefined}
                            onClick={() => setConfirmingDiscard(true)}
                        >
                            {t('b2b::company.discard_open')}
                        </ActionButton>
                    </div>

                    <DiscardModal open={confirmingDiscard} onOpenChange={setConfirmingDiscard} busy={waiting > 0} />
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

/** Marked by the last rejection and not replaced: the same, after trimming, as what it saw (§3.1). */
function fieldStillFlagged(field: string, draft: CompanyDraftData, lastSent: CompanyApplicationData | null): boolean {
    const sent = lastSent === null ? null : (fieldValues(lastSent.values)[field] ?? null);

    return draft.flags.some((flag) => flag.field === field) && (fieldValues(draft.values)[field] ?? '').trim() === (sent ?? '').trim();
}

/**
 * What the draft still needs before it can be sent (amendment 16(d)) - the same list the server
 * refuses on (§1.2): every value, a type still accepted, every required paper, nothing no longer
 * accepted, every marked item replaced, every request answered.
 */
function missingItems(page: CompanyPage, draft: CompanyDraftData, lastSent: CompanyApplicationData | null, t: Translate, figure: (value: number) => string): string[] {
    const values = fieldValues(draft.values);
    const items = (['name', 'company_type', 'cr_number', 'tax_number', 'address'] as const)
        .filter((field) => values[field] === null || (field === 'company_type' && draft.typeNoLongerAccepted))
        .map((field) => t(`b2b::company.field.${field}`));

    const documents = page.documentTypes.filter((type) => type.required && !type.greyed && !draft.documents.some((document) => document.documentTypeId === type.id)).length;
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
        ...(documents > 0 ? [t('b2b::company.missing_documents', { count: figure(documents) })] : []),
        ...(notAccepted > 0 ? [t('b2b::company.missing_not_accepted', { count: figure(notAccepted) })] : []),
        ...(flagged > 0 ? [t('b2b::company.missing_flagged', { count: figure(flagged) })] : []),
        ...(answers > 0 ? [t('b2b::company.missing_answers', { count: figure(answers) })] : []),
        ...(page.phoneConfirmed ? [] : [t('b2b::company.missing_phone')]),
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

/** A saved value shown as not valid before the field is left: cleared, or itself under the rules now. */
function standsOut(value: string, saved: string): boolean {
    return saved !== '' && (normal(value) === '' || normal(value) === normal(saved));
}

/** What keeps Send waiting, from how a field looks. */
function standingOf(look: Look): Standing | null {
    return look === 'saving' || look === 'refused' || look === 'invalid' || look === 'unsaved' ? look : null;
}

/**
 * What a field must have fixed, in words, or null: its value not valid or refused (the page's own
 * reason when the refusal's answer lets it name one, 17(g)), else the last decision's mark (17(f)).
 */
function problemOf(look: Look, problem: string | null, refusal: string | null, marked: string | null): string | null {
    if (look === 'refused') {
        return problem ?? refusal ?? marked;
    }

    return look === 'invalid' ? (problem ?? marked) : marked;
}

/**
 * Discarding the draft, asked in the page, never with the browser's own box (owner, 2026-09-24) -
 * in shadcn's AlertDialog, which confirms what destroys: the draft goes, and any file only it holds.
 * Being destructive, it opens on Cancel, so Enter never discards by accident. Not a typed
 * confirmation: Geist keeps that friction for what is hard to undo, and a draft is started again
 * with one press.
 */
export function DiscardModal({ open, onOpenChange, busy = false }: { open: boolean; onOpenChange: (open: boolean) => void; busy?: boolean }) {
    const t = useTranslator();
    const link = useLink();
    const [discarding, setDiscarding] = useState(false);
    const returnFocus = useReturnFocus(open);

    return (
        <AlertDialog
            open={open}
            // Not closed under a discard still out: its answer decides what the page shows next.
            onOpenChange={(next) => (discarding ? undefined : onOpenChange(next))}
        >
            <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start data-[size=default]:sm:max-w-md">
                <div className="grid gap-4 p-6">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-heading-20 text-ink">{t('b2b::company.discard_title')}</AlertDialogTitle>
                        <AlertDialogDescription className="text-copy-14 text-ink-muted" data-test="discard-confirmation">
                            {t('b2b::company.discard_confirm')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {/* A refusal keeps the dialog open; it is said inside it, not only in a toast
                        hidden under the backdrop (the review of the move). */}
                    <DialogError open={open} />
                </div>
                <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                    <AlertDialogCancel disabled={discarding} data-test="modal-cancel">
                        {t('ui.cancel')}
                    </AlertDialogCancel>
                    <ActionButton
                        variant="destructive"
                        data-test="discard-confirm"
                        loading={discarding}
                        // Not while a save is out: its page request would cancel the saves (17(i)).
                        disabledReason={busy ? t('b2b::company.saving_wait') : undefined}
                        onClick={() => {
                            setDiscarding(true);
                            router.post(link('storefront.company.discard'), {}, { onFinish: () => setDiscarding(false) });
                        }}
                    >
                        {t('b2b::company.discard_yes')}
                    </ActionButton>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
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

/**
 * A field's value, following what the server holds - but never over what the person has typed
 * since the save the server is answering (amendment 17(c)). `sending` says what was sent; a refusal
 * lets it go, since the server then holds nothing new.
 */
function useFollowed(saved: string): { value: string; setValue: (value: string) => void; sending: (value: string) => void; refused: () => void } {
    const [value, setValue] = useState(saved);
    const screen = useRef(value);
    const before = useRef(saved);
    const awaited = useRef<string | null>(null);

    screen.current = value;

    useEffect(() => {
        const previous = before.current;
        const waited = awaited.current;

        before.current = saved;

        if (waited !== null) {
            // An earlier save's answer: what is on screen is newer, and its own answer will come.
            if (normal(saved) !== normal(waited)) {
                return;
            }

            awaited.current = null;

            // The answer to what was sent last - taken only if nothing was typed since.
            if (normal(screen.current) === normal(waited)) {
                setValue(saved);
            }

            return;
        }

        // Changed from elsewhere: taken unless the person has an edit of their own in the field.
        if (normal(screen.current) === normal(previous)) {
            setValue(saved);
        }
    }, [saved]);

    return {
        value,
        setValue,
        sending: (sent) => {
            awaited.current = sent;
        },
        refused: () => {
            awaited.current = null;
        },
    };
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
 * A text field that saves itself when the person leaves it - only when it changed, and never while
 * it is not valid. An empty field nobody has left yet is not marked: it is listed beside Send.
 */
function SavedText({ field, label, saved, rule, required, flagged = false, multiline = false, figures = false }: SavedTextProps) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    // The draft as the server holds it - never over what the person typed since (17(c)).
    const followed = useFollowed(saved ?? '');
    const { value, setValue } = followed;
    const [left, setLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    // How many of its saves are out: one answering does not mean the next has.
    const [out, setOut] = useState(0);

    const locale = useLocale();
    const unsaved = normal(value) !== normal(saved ?? '');
    const problem = check(value, rule, required, t, subjectOf(label, locale), locale);
    const look = lookOf({
        saving: out > 0,
        refused: refusal !== null && refusal.value === value,
        // Red once left, never while a value is still being typed (Geist: validate on blur) - but at
        // once for a saved value that is cleared (17(k)) or no longer passes a raised minimum (16(b)).
        invalid: problem !== null && (left || standsOut(value, saved ?? '')),
        unsaved,
        // A field the last decision marked, not yet changed, is not "Saved" (17(f)).
        saved: (saved ?? '') !== '' && !flagged,
    });
    const shownProblem = problemOf(look, problem, refusal?.message ?? null, flagged ? t('b2b::company.flagged') : null);

    useEffect(() => report(field, standingOf(look)), [report, field, look]);

    // A field that leaves the page leaves nothing behind for Send to wait on.
    useEffect(() => () => report(field, null), [report, field]);

    const leave = () => {
        setLeft(true);

        // Never sent while it is not valid (amendment 16(a)): it stays red until it is.
        if (!unsaved || problem !== null) {
            return;
        }

        const sent = value;
        followed.sending(sent);
        setOut((count) => count + 1);
        send(
            link('storefront.company.save'),
            { [field]: normal(sent) === '' ? null : sent },
            (refused) => {
                if (refused !== null) {
                    followed.refused();
                }

                setRefusal(refused === null ? null : { value: sent, message: refused });
                setOut((count) => count - 1);
            },
            field,
        );
    };

    const id = `company-${field}`;
    const common = {
        id,
        name: field,
        value,
        'aria-invalid': shownProblem !== null || mustFix(look) ? true : undefined,
        'aria-describedby': `${id}-state`,
        'data-test': `field-${field}`,
        'data-look': look,
        dir: figures ? ('ltr' as const) : undefined,
        onBlur: leave,
    };

    return (
        <Field data-invalid={common['aria-invalid']}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <InputGroup>
                {multiline ? (
                    <InputGroupTextarea {...common} rows={3} onChange={(event) => setValue(event.target.value)} />
                ) : (
                    <InputGroupInput {...common} className={figures ? 'tw-figure' : undefined} onChange={(event) => setValue(event.target.value)} />
                )}
                <SaveMark look={look} align={multiline ? 'block-end' : 'inline-end'} />
            </InputGroup>
            <FieldState id={`${id}-state`} look={look} rule={ruleOf(rule, t, locale)} problem={shownProblem} />
        </Field>
    );
}

/**
 * The company type: one of the home store's, or "Other" and the company's own words (§1.3). A
 * greyed type is shown and cannot be chosen. Choosing "Other" saves nothing until the words are
 * valid and left - the type chosen before stays until then, and Send waits. Going back to
 * "Select a company type" is not a choice, and is not sent.
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
    const followedChoice = useFollowed(savedChoice);
    const followedWords = useFollowed(savedWords);
    const { value: choice, setValue: setChoice } = followedChoice;
    const { value: words, setValue: setWords } = followedWords;
    // Each its own: choosing "Other" does not mark its empty words red (17, L4).
    const [choiceLeft, setChoiceLeft] = useState(false);
    const [wordsLeft, setWordsLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [out, setOut] = useState(0);
    const saving = out > 0;

    const other = choice === OTHER;
    // What the page would send, so a refusal is known to be about what is on screen.
    const shown = other ? `${OTHER}:${words}` : choice;
    const refused = refusal !== null && refusal.value === shown;
    const unsaved = choice !== savedChoice || (other && normal(words) !== normal(savedWords));
    const choiceProblem = choice === '' ? t('b2b::company.check.required', { field: subjectOf(t('b2b::company.field.company_type'), locale) }) : null;
    const wordsProblem = other ? check(words, rule, true, t, subjectOf(t('b2b::company.field.other_words'), locale), locale) : null;
    // The type is marked while the one chosen is no longer offered, or the last decision marked it.
    const marked = draft.typeNoLongerAccepted ? t('b2b::company.type_no_longer') : flagged ? t('b2b::company.flagged') : null;

    const selectLook = other ? 'idle' : lookOf({ saving, refused, invalid: choiceProblem !== null && (choiceLeft || savedChoice !== ''), unsaved, saved: savedChoice !== '' && !flagged });
    const wordsLook = lookOf({
        saving,
        refused,
        invalid: wordsProblem !== null && (wordsLeft || standsOut(words, savedWords)),
        unsaved,
        saved: savedChoice === OTHER && !flagged,
    });
    const selectProblem = problemOf(selectLook, choiceProblem, refusal?.message ?? null, other ? null : marked);
    const wordsShownProblem = problemOf(wordsLook, wordsProblem, refusal?.message ?? null, other ? marked : null);

    useEffect(() => report('company_type', standingOf(other ? wordsLook : selectLook)), [report, other, wordsLook, selectLook]);

    useEffect(() => () => report('company_type', null), [report]);

    const save = (fields: Record<string, string | null>, sent: string) => {
        const chosen = fields.company_type_id ?? OTHER;
        followedChoice.sending(chosen);

        if (fields.company_type_other !== null && fields.company_type_other !== undefined) {
            followedWords.sending(fields.company_type_other);
        }

        setOut((count) => count + 1);
        send(
            link('storefront.company.save'),
            fields,
            (refusedWith) => {
                if (refusedWith !== null) {
                    followedChoice.refused();
                    followedWords.refused();
                }

                setRefusal(refusedWith === null ? null : { value: sent, message: refusedWith });
                setOut((count) => count - 1);
            },
            'company_type',
        );
    };

    return (
        <div className="grid gap-5">
            <Field data-invalid={selectProblem !== null || undefined}>
                <FieldLabel htmlFor="company-type">{t('b2b::company.field.company_type')}</FieldLabel>
                {/* A select is no input group: its save state sits beside it, at its end. */}
                <div className="flex items-center gap-3">
                    <div className="min-w-0 flex-1 [&>[data-slot=native-select-wrapper]]:w-full">
                        <NativeSelect
                            id="company-type"
                            data-test="field-company_type"
                            data-look={selectLook}
                            value={choice}
                            aria-invalid={selectProblem !== null || undefined}
                            aria-describedby="company-type-state"
                            onChange={(event) => {
                                const chosen = event.target.value;
                                setChoice(chosen);
                                setChoiceLeft(true);

                                if (chosen !== OTHER && chosen !== '') {
                                    save({ company_type_id: chosen, company_type_other: null }, chosen);
                                }
                            }}
                        >
                            {/* Stays a real option: going back to it is not sent, and says why. */}
                            <NativeSelectOption value="">{t('b2b::company.field.choose')}</NativeSelectOption>
                            {options.map((option) => (
                                <NativeSelectOption key={option.id} value={option.id} disabled={option.greyed}>
                                    {option.greyed ? `${nameOf(option, locale)} — ${t('b2b::company.greyed')}` : nameOf(option, locale)}
                                </NativeSelectOption>
                            ))}
                            {/* Always last, whatever staff set up: it is not a type (§1.3). */}
                            <NativeSelectOption value={OTHER}>{t('b2b::company.field.other')}</NativeSelectOption>
                        </NativeSelect>
                    </div>
                    {/* Never "Saved" beside a red line. */}
                    <SaveBeside look={selectProblem === null ? selectLook : 'idle'} />
                </div>
                <FieldState id="company-type-state" look={selectLook} rule={null} problem={selectProblem} />
            </Field>

            {other ? (
                <Field data-invalid={wordsShownProblem !== null || undefined}>
                    <FieldLabel htmlFor="company-type-other">{t('b2b::company.field.other_words')}</FieldLabel>
                    <InputGroup>
                        <InputGroupInput
                            id="company-type-other"
                            data-test="field-company_type_other"
                            data-look={wordsLook}
                            value={words}
                            aria-invalid={wordsShownProblem !== null || undefined}
                            aria-describedby="company-type-other-state"
                            onChange={(event) => setWords(event.target.value)}
                            onBlur={() => {
                                setWordsLeft(true);

                                if (unsaved && wordsProblem === null) {
                                    save({ company_type_id: null, company_type_other: words }, `${OTHER}:${words}`);
                                }
                            }}
                        />
                        <SaveMark look={wordsLook} />
                    </InputGroup>
                    <FieldState id="company-type-other-state" look={wordsLook} rule={ruleOf(rule, t, locale)} problem={wordsShownProblem} />
                </Field>
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
    const { report, queue } = useChanges();
    const [picking, setPicking] = useState<string | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const look = lookOf({ saving: picking !== null, refused: refusal !== null, invalid: false, unsaved: false, saved: draft.values.address !== null && !flagged });

    // Only a save under way keeps Send waiting: a refused pick changes nothing that was saved.
    useEffect(() => report('address', picking !== null ? 'saving' : null), [report, picking]);

    useEffect(() => () => report('address', null), [report]);

    return (
        <AddressPicker
            addresses={page.savedAddresses}
            pickedId={draft.values.addressId}
            pending={picking}
            kept={draft.values.address}
            look={look}
            message={refusal}
            marked={flagged ? t('b2b::company.flagged') : null}
            locale={locale}
            // After the saves still waiting: leaving now would cancel them (17(i)).
            onAdd={() => queue(() => router.visit(link('storefront.account', { tab: 'addresses', return: 'b2b.company' })))}
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

/**
 * One paper's row (Geist's Entity, shadcn's Item): its name and where it stands, then at most two
 * buttons - Choose File or Replace File, and Open File - with Remove File in its ⋯ menu, confirmed
 * first. What is wrong with it is said under it in red: the page's own warning before an upload, the
 * server's refusal, a file no longer accepted, the last decision's mark.
 */
function FileItem({
    titleId,
    title,
    status,
    picker,
    mediaId,
    greyed = false,
    problems,
    remove,
    test,
    fileTest,
}: {
    titleId: string;
    title: string;
    status: ReactNode;
    picker: ReturnType<typeof useFilePicker>;
    mediaId: string | null;
    greyed?: boolean;
    problems: { text: string; test?: string }[];
    remove: () => void;
    test?: string;
    fileTest?: string;
}) {
    const t = useTranslator();
    const link = useLink();
    const [confirming, setConfirming] = useState(false);
    const more = useRef<HTMLButtonElement>(null);
    const choose = useRef<HTMLButtonElement>(null);
    // Removed, the file's ⋯ and Open File go once the answer comes: focus goes to Choose File, which
    // stays. Cancelled, it goes back to the ⋯ button that opened the dialog.
    const removed = useRef(false);
    const back = useMemo<RefObject<HTMLElement | null>>(() => ({ get current() { return removed.current ? choose.current : more.current; } }), []);
    const returnFocus = useReturnFocus(confirming, back);

    return (
        <li className="grid gap-2" data-test={test}>
            <Item variant="outline" size="sm" className="rounded-[var(--tw-radius)] border-line">
                <ItemContent className="min-w-0">
                    <ItemTitle id={titleId} className="text-label-14 text-ink">
                        {title}
                    </ItemTitle>
                    <ItemDescription className="line-clamp-none text-copy-13 text-ink-muted">{status}</ItemDescription>
                </ItemContent>
                <ItemActions className="flex-wrap">
                    {greyed ? null : (
                        <>
                            <input ref={picker.input} type="file" accept={ACCEPT} className="hidden" onChange={picker.onChange} data-test={fileTest} />
                            {/* The buttons keep their own words and are described by the paper's
                                name, so three "Replace File" buttons still say which each is for. */}
                            <ActionButton ref={choose} variant="outline" size="sm" loading={picker.sending} aria-describedby={titleId} onClick={picker.pick}>
                                {mediaId === null ? t('b2b::company.choose_file') : t('b2b::company.replace')}
                            </ActionButton>
                        </>
                    )}
                    {mediaId !== null ? (
                        <>
                            {/* A new tab, so the form and the saves it is waiting on stay where they are. */}
                            <Button asChild variant="ghost" size="sm">
                                <a href={link('storefront.company.file', { file: mediaId })} target="_blank" rel="noopener noreferrer" aria-describedby={titleId}>
                                    <ExternalLink aria-hidden="true" />
                                    {t('b2b::company.open')}
                                </a>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button ref={more} type="button" variant="ghost" size="icon-sm" aria-label={`${t('ui.more_actions')}: ${title}`} title={t('ui.more_actions')} data-test={test === undefined ? undefined : `${test}-menu`}>
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={() => {
                                            removed.current = false;
                                            setConfirming(true);
                                        }}
                                        data-test={test === undefined ? undefined : `${test}-remove`}
                                    >
                                        {t('b2b::company.remove_open')}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    ) : null}
                </ItemActions>
            </Item>

            {problems.map((problem) => (
                <FieldError key={problem.text} data-test={problem.test} className="text-copy-13">
                    {problem.text}
                </FieldError>
            ))}

            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent onCloseAutoFocus={returnFocus} className="material-modal gap-0 overflow-hidden border-0 p-0 text-start data-[size=default]:sm:max-w-md">
                    <div className="grid gap-4 p-6">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="text-heading-20 text-ink">{t('b2b::company.remove_title')}</AlertDialogTitle>
                            <AlertDialogDescription className="text-copy-14 text-ink-muted">{t('b2b::company.remove_confirm', { name: title })}</AlertDialogDescription>
                        </AlertDialogHeader>
                    </div>
                    <AlertDialogFooter className="border-t border-line bg-surface-sunken px-6 py-4">
                        <AlertDialogCancel data-test="modal-cancel">{t('ui.cancel')}</AlertDialogCancel>
                        <ActionButton
                            variant="destructive"
                            data-test={test === undefined ? undefined : `${test}-remove-confirm`}
                            onClick={() => {
                                // Queued with the other changes; its answer, a refusal included, is said under the row.
                                removed.current = true;
                                remove();
                                setConfirming(false);
                            }}
                        >
                            {t('b2b::company.remove')}
                        </ActionButton>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </li>
    );
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

        return same === undefined ? null : t('b2b::company.duplicate_file', { section: nameOf({ nameAr: same.documentTypeNameAr ?? '', nameEn: same.documentTypeNameEn ?? '' }, locale) });
    });
    const [removal, setRemoval] = useState<string | null>(null);
    // Replaced once the file under the type is not the one the rejection saw; a type no longer
    // offered stops counting (§3.1).
    const stillFlagged = flagged && !type.greyed && (file === undefined || file.mediaId === sentMediaId);
    const refusal = picker.refusal ?? removal;

    const status =
        file !== undefined ? (
            // The file's name cut in the middle, as every file name is (Geist's Middle Truncate), and
            // when it was uploaded under it: a long name never pushes the moment out of the row.
            <span className="grid min-w-0 gap-0.5">
                <MiddleTruncate value={file.fileName} />
                <span>
                    <Phrase text={t('b2b::company.uploaded', { date: MARK })} moment={<Time value={file.uploadedAt} inSentence />} />
                </span>
            </span>
        ) : type.greyed ? (
            t('b2b::company.greyed')
        ) : type.required ? (
            t('b2b::company.required')
        ) : (
            t('b2b::company.optional')
        );

    return (
        <FileItem
            titleId={`document-${type.id}-name`}
            title={nameOf(type, locale)}
            status={status}
            picker={picker}
            mediaId={file?.mediaId ?? null}
            greyed={type.greyed}
            problems={[
                ...(file?.noLongerAccepted ? [{ text: t('b2b::company.no_longer_accepted') }] : []),
                ...(stillFlagged ? [{ text: t('b2b::company.flagged_document'), test: 'flagged' }] : []),
                ...(picker.warning !== null ? [{ text: picker.warning, test: 'file-warning' }] : []),
                ...(refusal !== null ? [{ text: refusal }] : []),
            ]}
            remove={() => send(link('storefront.company.detach', { type: type.id }), {}, setRemoval, errorKey)}
            test={`document-${type.id}`}
            fileTest={`file-${type.id}`}
        />
    );
}

function RequestRow({ request, answer, rule, maxBytes }: { request: CompanyRequestData; answer: CompanyAnswerData | null; rule: CompanyFieldRuleData | undefined; maxBytes: number }) {
    const t = useTranslator();
    const link = useLink();
    const send = useSend();
    const { report } = useChanges();
    const url = link('storefront.company.answer', { answered: request.id });
    const errorKey = `answers.${request.id}`;
    const picker = useFilePicker(url, maxBytes, errorKey);
    const locale = useLocale();
    const savedText = answer?.text ?? '';
    const followed = useFollowed(savedText);
    const { value: text, setValue: setText } = followed;
    const [left, setLeft] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [removal, setRemoval] = useState<string | null>(null);
    const [out, setOut] = useState(0);
    const id = `request-${request.id}`;
    const stateId = `${id}-state`;
    const written = request.kind === 'TEXT';

    const problem = written ? check(text, rule, true, t, subjectOf(t('b2b::company.answer'), locale), locale) : null;
    const look = written
        ? lookOf({
              saving: out > 0,
              refused: refusal !== null && refusal.value === text,
              invalid: problem !== null && (left || standsOut(text, savedText)),
              unsaved: normal(text) !== normal(savedText),
              saved: savedText !== '',
          })
        : 'idle';
    const shownProblem = problemOf(look, problem, refusal?.message ?? null, null);

    useEffect(() => report(`answers.${request.id}`, standingOf(look)), [report, request.id, look]);

    useEffect(() => () => report(`answers.${request.id}`, null), [report, request.id]);

    if (!written) {
        const shown = picker.refusal ?? removal;

        return (
            <FileItem
                titleId={`${id}-label`}
                title={request.label}
                status={answer?.mediaId ? t('b2b::company.answered_file') : t('b2b::company.required')}
                picker={picker}
                mediaId={answer?.mediaId ?? null}
                problems={[...(picker.warning !== null ? [{ text: picker.warning }] : []), ...(shown !== null ? [{ text: shown }] : [])]}
                remove={() => send(link('storefront.company.unanswer', { answered: request.id }), {}, setRemoval, errorKey)}
                test={id}
            />
        );
    }

    return (
        // What was asked is the field's own label (Geist's Label), not a paragraph pointed at.
        <li data-test={id}>
            <Field data-invalid={shownProblem !== null || undefined}>
                <FieldLabel htmlFor={`${id}-text`}>{request.label}</FieldLabel>
                <InputGroup>
                    <InputGroupTextarea
                        id={`${id}-text`}
                        aria-invalid={shownProblem !== null || undefined}
                        aria-describedby={stateId}
                        data-look={look}
                        rows={3}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        onBlur={() => {
                            setLeft(true);

                            // An empty answer is no answer, and is not sent: it stays red (22(a)).
                            if (normal(text) === normal(savedText) || problem !== null) {
                                return;
                            }

                            const sent = text;
                            followed.sending(sent);
                            setOut((count) => count + 1);
                            send(
                                url,
                                { text: sent },
                                (refused) => {
                                    if (refused !== null) {
                                        followed.refused();
                                    }

                                    setRefusal(refused === null ? null : { value: sent, message: refused });
                                    setOut((count) => count - 1);
                                },
                                errorKey,
                            );
                        }}
                    />
                    <SaveMark look={look} align="block-end" />
                </InputGroup>
                <FieldState id={stateId} look={look} rule={ruleOf(rule, t, locale)} problem={shownProblem} />
            </Field>
        </li>
    );
}
