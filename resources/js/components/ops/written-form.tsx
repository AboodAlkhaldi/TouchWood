import { useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { PanelDialog } from '@/components/PanelDialog';
import { TextareaField, TextField } from '@/components/Fields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { figure, toLatinDigits } from '@/lib/digits';
import { useInLanguage } from '@/lib/in-language';
import { readJson, useLiveRead } from '@/lib/live-read';
import { useTranslator } from '@/lib/t';
import type { Checks } from '@/lib/use-checks';
import type { CustomerToMessageData, SegmentChoiceData, SegmentsPageData, StoreAudienceData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| What New Message and New and Edit Notice share (ops.md §4.15 O4, O6): the two languages' boxes, how it goes, the customer
| and segment pickers, the question before anything goes to many, and where a refusal is brought into view.
*/

/** Where each refused box of these forms is, in the page's order. */
const REFUSED_BOXES: [string, string][] = [
    ['audience', '[data-test="who"]'],
    ['customer', '[data-test="who"]'],
    ['title_ar', '#title_ar'],
    ['title_en', '#title_en'],
    ['body_ar', '#body_ar'],
    ['body_en', '#body_en'],
    ['link', '#link'],
    ['channels', '[data-test="how"]'],
    ['send_at', '[data-test="when"]'],
];

/** A box's refusal has no toast, and the form keeps its scroll: the first refused box is brought into view. */
export function showRefusal(errors: Record<string, string>): void {
    const found = REFUSED_BOXES.find(([field]) => errors[field] !== undefined);

    if (found !== undefined) {
        window.requestAnimationFrame(() => document.querySelector(found[1])?.scrollIntoView({ block: 'center' }));
    }
}

/** A store's name on a form: a Super Admin is offered the off ones too, and marked, as StoreFilter marks them. */
export function storeOption(store: { name: string; isOn: boolean }, t: ReturnType<typeof useTranslator>): string {
    return store.isOn ? store.name : t('admin.store.option_off', { store: store.name });
}

type BoxesForm = {
    data: { title_ar: string; title_en: string; body_ar: string; body_en: string };
    errors: Partial<Record<'title_ar' | 'title_en' | 'body_ar' | 'body_en', string>>;
    setData: (key: 'title_ar' | 'title_en' | 'body_ar' | 'body_en', value: string) => void;
};

/** The four rules of §4.3, as the form's checks declare them. */
export function messageBoxes(data: BoxesForm['data'], t: ReturnType<typeof useTranslator>) {
    return [
        { id: 'title_ar', label: t('ops::admin_written.fields.title_ar'), value: data.title_ar, rules: { required: true, length: { max: 200 } } },
        { id: 'title_en', label: t('ops::admin_written.fields.title_en'), value: data.title_en, rules: { required: true, length: { max: 200 } } },
        { id: 'body_ar', label: t('ops::admin_written.fields.body_ar'), value: data.body_ar, rules: { required: true, length: { max: 500 } } },
        { id: 'body_en', label: t('ops::admin_written.fields.body_en'), value: data.body_en, rules: { required: true, length: { max: 500 } } },
    ];
}

/** Arabic and English side by side, stacked on a phone, each in its own direction (Q11). */
export function MessageBoxes({ form, checks }: { form: BoxesForm; checks: Checks }) {
    const t = useTranslator();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {(['ar', 'en'] as const).map((language) => {
                const title = `title_${language}` as const;
                const body = `body_${language}` as const;
                const dir = language === 'ar' ? 'rtl' : 'ltr';

                return (
                    <div key={language} className="grid content-start gap-4">
                        <TextField
                            id={title}
                            dir={dir}
                            label={t(`ops::admin_written.fields.${title}`)}
                            helper={t('ops::admin_written.fields.title_rule')}
                            check={checks.box(title, form.errors[title])}
                            value={form.data[title]}
                            onChange={(event) => form.setData(title, toLatinDigits(event.target.value))}
                            data-test={title}
                        />
                        <TextareaField
                            id={body}
                            dir={dir}
                            rows={5}
                            label={t(`ops::admin_written.fields.${body}`)}
                            helper={t('ops::admin_written.fields.body_rule')}
                            check={checks.box(body, form.errors[body])}
                            value={form.data[body]}
                            onChange={(event) => form.setData(body, toLatinDigits(event.target.value))}
                            data-test={body}
                        />
                    </div>
                );
            })}
        </div>
    );
}

/** Bell and Email: at least one. */
export function HowItGoes({ bell, email, onBell, onEmail, emailNote, error }: { bell: boolean; email: boolean; onBell: (on: boolean) => void; onEmail: (on: boolean) => void; emailNote: string; error?: string }) {
    const t = useTranslator();

    return (
        <FieldSet className="gap-3" data-test="how">
            <FieldLegend variant="label" className="text-label-14 text-ink">
                {t('ops::admin_written.fields.how')}
            </FieldLegend>
            <Field orientation="horizontal">
                <Checkbox id="by-bell" checked={bell} onCheckedChange={(on) => onBell(on === true)} data-test="by-bell" />
                <FieldLabel htmlFor="by-bell" className="font-normal">
                    {t('ops::admin_written.fields.bell')}
                </FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Checkbox id="by-email" checked={email} onCheckedChange={(on) => onEmail(on === true)} data-test="by-email" />
                <FieldLabel htmlFor="by-email" className="font-normal">
                    {t('ops::admin_written.fields.email')}
                </FieldLabel>
            </Field>
            <FieldDescription data-test="email-note">{emailNote}</FieldDescription>
            {!bell && !email ? <FieldError data-test="channels-rule">{t('ops::admin_written.fields.channels_rule')}</FieldError> : error ? <FieldError role="alert">{error}</FieldError> : null}
        </FieldSet>
    );
}

function PickerFrame({
    id,
    label,
    shown,
    open,
    onOpen,
    helper,
    error,
    children,
}: {
    id: string;
    label: string;
    shown: string;
    open: boolean;
    onOpen: (open: boolean) => void;
    helper?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <Field>
            <FieldLabel id={`${id}-label`} htmlFor={id}>
                {label}
            </FieldLabel>
            <Popover open={open} onOpenChange={onOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-labelledby={`${id}-label ${id}-value`}
                        aria-invalid={error ? true : undefined}
                        className="w-full justify-between font-normal"
                        data-test={id}
                    >
                        <span id={`${id}-value`} className="truncate">
                            {shown}
                        </span>
                        <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
                    {children}
                </PopoverContent>
            </Popover>
            {helper ? <FieldDescription data-test={`${id}-helper`}>{helper}</FieldDescription> : null}
            {error ? (
                <FieldError role="alert" data-test={`${id}-error`}>
                    {error}
                </FieldError>
            ) : null}
        </Field>
    );
}

/** One customer of the store, searched from 3 characters; the chosen one shows with Change. */
export function CustomerPicker({ store, chosen, onChoose, error }: { store: string; chosen: CustomerToMessageData | null; onChoose: (customer: CustomerToMessageData | null) => void; error?: string }) {
    const t = useTranslator();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const typed = query.trim();
    const found = useLiveRead<{ customers: CustomerToMessageData[] }>('/admin/messages/customers', open && typed.length >= 3 ? { store, search: typed } : null);
    const customers = found.data?.customers ?? [];

    if (chosen !== null) {
        return (
            <Field>
                <FieldLabel>{t('ops::admin_written.customer.label')}</FieldLabel>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-3" data-test="chosen-customer">
                    <CustomerLines customer={chosen} />
                    <Button type="button" variant="outline" size="sm" onClick={() => onChoose(null)} data-test="change-customer">
                        {t('ops::admin_written.customer.change')}
                    </Button>
                </div>
                {error ? <FieldError role="alert">{error}</FieldError> : null}
            </Field>
        );
    }

    return (
        <PickerFrame id="customer" label={t('ops::admin_written.customer.label')} shown={t('ops::admin_written.customer.choose')} open={open} onOpen={setOpen} helper={t('ops::admin_written.customer.hint')} error={error}>
            <Command shouldFilter={false}>
                <CommandInput placeholder={t('ops::admin_written.customer.search')} value={query} onValueChange={setQuery} className="h-9" data-test="customer-search" />
                <CommandList aria-busy={found.loading || undefined}>
                    {typed.length < 3 ? (
                        <p className="p-3 text-copy-13 text-ink-muted" data-test="customer-more">
                            {t('ops::admin_written.customer.more')}
                        </p>
                    ) : found.failed ? (
                        <div className="grid justify-items-start gap-2 p-3 text-copy-13 text-ink-muted">
                            {t('ops::admin_written.customer.failed')}
                            <Button type="button" size="sm" variant="outline" onClick={found.retry}>
                                {t('ops::admin_written.customer.try_again')}
                            </Button>
                        </div>
                    ) : found.data === null ? (
                        // Until the first answer, the pause before the read included: nothing is known to be missing yet.
                        <div className="flex justify-center p-3">
                            <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
                        </div>
                    ) : (
                        <CommandEmpty data-test="customer-none">{t('ops::admin_written.customer.none')}</CommandEmpty>
                    )}
                    {typed.length >= 3
                        ? customers.map((customer) => (
                              <CommandItem
                                  key={customer.id}
                                  value={customer.id}
                                  onSelect={() => {
                                      onChoose(customer);
                                      setOpen(false);
                                      setQuery('');
                                  }}
                                  data-test={`customer-${customer.id}`}
                              >
                                  <CustomerLines customer={customer} />
                              </CommandItem>
                          ))
                        : null}
                </CommandList>
            </Command>
        </PickerFrame>
    );
}

function CustomerLines({ customer }: { customer: CustomerToMessageData }) {
    return (
        <span className="grid min-w-0 gap-0.5">
            <span className="truncate text-label-14 text-ink">{`${customer.firstName} ${customer.lastName}`.trim()}</span>
            <span className="truncate text-copy-13 text-ink-muted">{customer.email}</span>
            {customer.phone === null ? null : (
                <bdi dir="ltr" className="tw-figure truncate text-start text-copy-13 text-ink-muted">
                    {customer.phone}
                </bdi>
            )}
        </span>
    );
}

/** A store's segments, read when opened, newest first, Show More inside. One with no members is listed, not offered. */
export function SegmentPicker({ path, store, chosen, onChoose, error }: { path: string; store: string; chosen: SegmentChoiceData | null; onChoose: (segment: SegmentChoiceData) => void; error?: string }) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const { locale } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<SegmentChoiceData[] | null>(null);
    const [next, setNext] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const read = (after: string | null) => {
        setLoading(true);
        setFailed(false);
        readJson<SegmentsPageData>(path, { store, after }).then((answer) => {
            setLoading(false);

            if (answer === null) {
                setFailed(true);

                return;
            }

            setRows((before) => [...(before ?? []), ...answer.segments]);
            setNext(answer.next);
        });
    };

    return (
        <PickerFrame
            id="segment"
            label={t('ops::admin_written.segments.label')}
            shown={chosen === null ? t('ops::admin_written.segments.choose') : inLanguage(chosen.nameAr, chosen.nameEn)}
            open={open}
            onOpen={(next) => {
                setOpen(next);

                if (next && rows === null && !loading) {
                    read(null);
                }
            }}
            error={error}
        >
            {/* A search box, as the panel's other pickers have: the keyboard's way into the list. It looks through the rows read. */}
            <Command>
                <CommandInput placeholder={t('ops::admin_written.segments.search')} className="h-9" data-test="segment-search" />
                <CommandList aria-busy={loading || undefined}>
                    {rows !== null && rows.length === 0 ? (
                        <p className="p-3 text-copy-13 text-ink-muted" data-test="segments-none">
                            {t('ops::admin_written.segments.none')}
                        </p>
                    ) : null}
                    {(rows ?? []).map((segment) => (
                        <CommandItem
                            key={segment.id}
                            value={`${inLanguage(segment.nameAr, segment.nameEn)} ${segment.id}`}
                            disabled={segment.memberCount === 0}
                            onSelect={() => {
                                onChoose(segment);
                                setOpen(false);
                            }}
                            data-test={`segment-${segment.id}`}
                        >
                            <span className="grid min-w-0 gap-0.5">
                                <span className="truncate text-label-14">{inLanguage(segment.nameAr, segment.nameEn)}</span>
                                <span className="text-copy-13 text-ink-muted">
                                    {segment.memberCount === 0
                                        ? t('ops::admin_written.segments.no_members')
                                        : segment.memberCount === 1
                                          ? t('ops::admin_written.segments.members_one')
                                          : t('ops::admin_written.segments.members', { count: figure(locale, segment.memberCount) })}
                                </span>
                            </span>
                        </CommandItem>
                    ))}
                    {failed ? (
                        <div className="grid justify-items-start gap-2 p-3 text-copy-13 text-ink-muted">
                            {t('ops::admin_written.segments.failed')}
                            <Button type="button" size="sm" variant="outline" onClick={() => read(next)}>
                                {t('ops::admin_written.customer.try_again')}
                            </Button>
                        </div>
                    ) : loading ? (
                        <div className="flex justify-center p-3">
                            <Spinner aria-label={t('ui.loading')} className="text-ink-muted" />
                        </div>
                    ) : next !== null ? (
                        <div className="p-2">
                            <Button type="button" variant="outline" size="sm" className="w-full" onClick={() => read(next)} data-test="segments-more">
                                {t('ops::admin_written.segments.more')}
                            </Button>
                        </div>
                    ) : null}
                </CommandList>
            </Command>
        </PickerFrame>
    );
}

/**
 * The question before anything goes to many (Q12), in the owner's words (2026-11-02). A whole store's count is read when
 * it opens (§9.4 Q14); a segment's is the one the picker showed. A count of 0 says so and offers no Send.
 */
export function SendToManyDialog({
    store,
    segment,
    byBell,
    label,
    busy,
    onClose,
    onSend,
}: {
    store: { code: string; name: string };
    segment: SegmentChoiceData | null;
    byBell: boolean;
    label: string;
    busy: boolean;
    onClose: () => void;
    onSend: () => void;
}) {
    const t = useTranslator();
    const inLanguage = useInLanguage();
    const { locale } = usePage<SharedProps>().props;
    const [read, setRead] = useState<number | null>(null);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const count = segment === null ? read : segment.memberCount;

    useEffect(() => {
        if (segment !== null) {
            return;
        }

        let current = true;
        setFailed(false);
        readJson<StoreAudienceData>('/admin/ops/audience', { store: store.code }).then((answer) => {
            if (current) {
                setRead(answer?.count ?? null);
                setFailed(answer === null);
            }
        });

        return () => {
            current = false;
        };
    }, [segment, store.code, attempt]);

    const values = { count: figure(locale, count ?? 0), store: store.name, segment: segment === null ? '' : inLanguage(segment.nameAr, segment.nameEn) };
    const asked = (many: string, one: string) => t(count === 1 ? one : many, values);
    const title =
        count === null
            ? t('ops::admin_written.confirm.title_reading')
            : segment === null
              ? byBell
                  ? asked('ops::admin_written.confirm.store_bell', 'ops::admin_written.confirm.store_bell_one')
                  : asked('ops::admin_written.confirm.store_email', 'ops::admin_written.confirm.store_email_one')
              : byBell
                ? asked('ops::admin_written.confirm.segment_bell', 'ops::admin_written.confirm.segment_bell_one')
                : asked('ops::admin_written.confirm.segment_email', 'ops::admin_written.confirm.segment_email_one');

    return (
        <PanelDialog
            open
            onOpenChange={(next) => (next ? undefined : onClose())}
            title={title}
            description={t('ops::admin_written.confirm.recall')}
            busy={busy}
            cancelLabel={t('ops::admin_written.confirm.cancel')}
            confirm={
                count !== null && count > 0 ? (
                    <ActionButton type="button" loading={busy} onClick={onSend} data-test="confirm-send">
                        {label}
                    </ActionButton>
                ) : null
            }
        >
            {count === 0 ? (
                <p className="text-copy-14 text-ink" data-test="nobody">
                    {segment === null ? t('ops::admin_written.confirm.none_store') : t('ops::admin_written.confirm.none_segment')}
                </p>
            ) : failed ? (
                <div className="grid justify-items-start gap-2 text-copy-14 text-ink" data-test="count-failed">
                    {t('ops::admin_written.confirm.read_failed')}
                    <Button type="button" size="sm" variant="outline" onClick={() => setAttempt((before) => before + 1)}>
                        {t('ops::admin_written.confirm.try_again')}
                    </Button>
                </div>
            ) : count === null ? (
                <p className="flex items-center gap-2 text-copy-14 text-ink-muted">
                    <Spinner aria-hidden="true" />
                    {t('ops::admin_written.confirm.reading')}
                </p>
            ) : null}
        </PanelDialog>
    );
}
