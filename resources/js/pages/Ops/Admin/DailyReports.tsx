import { lazy, Suspense, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarIcon } from 'lucide-react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { LoadMoreButton } from '@/components/LoadMoreButton';
import { StoreOffBadge } from '@/components/StoreOffBadge';
import { formatMoment, Time } from '@/components/Time';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldDescription, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NativeSelectOption } from '@/components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AdminLayout } from '@/layouts/AdminLayout';
import { intlLocale, toLatinDigits } from '@/lib/digits';
import { fileSize } from '@/lib/file-size';
import { lastPastDay } from '@/lib/daily-report-days';
import { useKeptDepth } from '@/lib/kept-depth';
import { TIME, wallClock, zoneName } from '@/lib/moments';
import { useTranslator } from '@/lib/t';
import { useLoadMore } from '@/lib/use-load-more';
import type { DailyReportData, DailyReportEmailData, DailyReportsScreenData, StoreChoiceData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

// Loaded when the picker first opens, never with the page (DateCalendar, the page budget).
const DateCalendar = lazy(() => import('@/components/DateCalendar'));

/*
| O9 · Daily Reports (ops.md §4.15; Q22, Q23): make a report now, the reader's own daily emails, and the reports kept.
| Every store's reports are one list here: nothing on this page is read for a chosen store, so the address names none.
| A store's report and email are read in its own time, every store's in the base store's.
*/

const LIST = '/admin/daily-reports';
const EMAILS = `${LIST}/emails`;
/** The time a row never set shows (Q23). */
const FIRST_TIME = '21:00';

function asDate(day: string): Date {
    const [year, month, date] = day.split('-').map(Number);

    return new Date(year as number, (month as number) - 1, date as number);
}

export default function DailyReports({ reports, next, emails, stores, emailStores, allStores, zones }: DailyReportsScreenData) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const list = useLoadMore<DailyReportData>(reports, (report) => report.id);
    const more = (cursor: string) => list.more(LIST, { after: cursor }, ['reports', 'next']);
    // Making a report sends back the first page: the rows shown with Show More are asked for again.
    const restoring = useKeptDepth(list, next, more);
    const day = new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

    return (
        <AdminLayout title={t('ops::admin_daily_reports.title')} subtitle={t('ops::admin_daily_reports.subtitle')}>
            <div className="grid gap-6">
                <FormError />

                <MakeReport stores={stores} allStores={allStores} zones={zones} />
                <DailyEmails emails={emails} stores={emailStores} allStores={allStores} zones={zones} />

                <section className="grid gap-3" aria-labelledby="reports-made">
                    <h2 id="reports-made" className="text-heading-20 text-ink">
                        {t('ops::admin_daily_reports.list.title')}
                    </h2>
                    {list.rows.length === 0 ? (
                        <Empty className="material-base" data-test="reports-empty">
                            <EmptyHeader>
                                <EmptyTitle className="text-heading-16 text-ink">{t('ops::admin_daily_reports.list.empty')}</EmptyTitle>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <>
                            <div className="material-base max-w-full overflow-x-auto">
                                <Table>
                                    <TableCaption className="sr-only">{t('ops::admin_daily_reports.list.title')}</TableCaption>
                                    <TableHeader className="bg-surface-sunken">
                                        <TableRow>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.store')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.day')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.made_by')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.made')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.language')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.size')}</TableHead>
                                            <TableHead>{t('ops::admin_daily_reports.list.columns.emailed')}</TableHead>
                                            <TableHead>
                                                <span className="sr-only">{t('ops::admin_daily_reports.list.columns.file')}</span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {list.rows.map((report) => (
                                            <TableRow key={report.id} data-test={`report-${report.id}`}>
                                                <TableCell className="text-ink" data-test="store">
                                                    {report.storeName}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap text-ink-muted" data-test="day">
                                                    <span className="inline-flex items-center gap-2">
                                                        <span className="tw-figure">{day.format(new Date(`${report.day}T00:00:00Z`))}</span>
                                                        {report.pastDay ? (
                                                            <Badge variant="outline" data-test="past-day">
                                                                {t('ops::admin_daily_reports.list.past_day')}
                                                            </Badge>
                                                        ) : null}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-ink-muted">{report.madeBy}</TableCell>
                                                <TableCell className="whitespace-nowrap text-ink-muted">
                                                    <Time value={report.madeAt} />
                                                </TableCell>
                                                <TableCell className="text-ink-muted">{t(`ops::admin_daily_reports.language.${report.language}`)}</TableCell>
                                                <TableCell className="tw-figure whitespace-nowrap text-ink-muted">{fileSize(report.bytes, locale)}</TableCell>
                                                <TableCell className="whitespace-nowrap text-ink-muted">
                                                    {report.emailedAt === null ? (
                                                        <span>
                                                            <span aria-hidden="true">—</span>
                                                            <span className="sr-only">{t('ops::admin_daily_reports.list.none')}</span>
                                                        </span>
                                                    ) : (
                                                        <Time value={report.emailedAt} />
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-end">
                                                    <Button asChild variant="outline" size="sm">
                                                        <a href={report.downloadUrl} className="whitespace-nowrap" data-test="download">
                                                            {t('ops::admin_daily_reports.list.download')}
                                                        </a>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            {next !== null ? <LoadMoreButton loading={list.loading || restoring} onClick={() => more(next)} /> : null}
                        </>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}

function storeLabel(store: StoreChoiceData, t: ReturnType<typeof useTranslator>): string {
    return store.isOn ? store.name : t('admin.store.option_off', { store: store.name });
}

function MakeReport({ stores, allStores, zones }: Pick<DailyReportsScreenData, 'stores' | 'allStores' | 'zones'>) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const opening = stores.find((store) => store.isOn)?.id ?? stores[0]?.id ?? '';
    const [store, setStore] = useState(opening);
    const [past, setPast] = useState(false);
    // Only a day the reader picked is kept: until then it is always the chosen store's yesterday.
    const [picked, setPicked] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    if (stores.length === 0 && !allStores) {
        return (
            <Card>
                <CardContent className="text-copy-14 text-ink-muted" data-test="no-store">
                    {t('ops::admin_daily_reports.no_store')}
                </CardContent>
            </Card>
        );
    }

    const latest = lastPastDay(store, zones);
    const day = picked !== null && picked <= latest ? picked : latest;
    const today = wallClock(zones[store] ?? 'UTC', Date.now()).date;
    const shown = new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', year: 'numeric' }).format(asDate(day));

    return (
        <Card>
            <CardContent className="grid gap-4">
                <h2 className="text-heading-20 text-ink">{t('ops::admin_daily_reports.make.title')}</h2>
                <SelectField
                    id="make-store"
                    label={t('ops::admin_daily_reports.make.store')}
                    className="max-w-xs"
                    value={store}
                    onChange={(event) => setStore(event.target.value)}
                    data-test="make-store"
                >
                    {allStores ? <NativeSelectOption value="">{t('admin.store.all')}</NativeSelectOption> : null}
                    {stores.map((each) => (
                        <NativeSelectOption key={each.id} value={each.id}>
                            {storeLabel(each, t)}
                        </NativeSelectOption>
                    ))}
                </SelectField>

                <FieldSet className="gap-3">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('ops::admin_daily_reports.make.day')}
                    </FieldLegend>
                    <RadioGroup value={past ? 'past' : 'today'} onValueChange={(next) => setPast(next === 'past')}>
                        <Field orientation="horizontal">
                            <RadioGroupItem id="make-today" value="today" data-test="make-today" />
                            <FieldLabel htmlFor="make-today" className="font-normal">
                                {t('ops::admin_daily_reports.make.today')}
                            </FieldLabel>
                        </Field>
                        <Field orientation="horizontal">
                            <RadioGroupItem id="make-past" value="past" data-test="make-past" />
                            <FieldLabel htmlFor="make-past" className="font-normal">
                                {t('ops::admin_daily_reports.make.past')}
                            </FieldLabel>
                        </Field>
                    </RadioGroup>

                    {past ? (
                        <Field className="w-auto">
                            <FieldLabel id="make-date-label" htmlFor="make-date">
                                {t('ops::admin_daily_reports.make.date')}
                            </FieldLabel>
                            <Popover open={open} onOpenChange={setOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        id="make-date"
                                        type="button"
                                        variant="outline"
                                        aria-labelledby="make-date-label make-date-value"
                                        className="w-fit min-w-40 justify-start font-normal"
                                        data-test="make-date"
                                    >
                                        <CalendarIcon aria-hidden="true" className="opacity-60" />
                                        <span id="make-date-value" className="tw-figure truncate">
                                            {shown}
                                        </span>
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-auto p-2" align="start">
                                    <Suspense
                                        fallback={
                                            <div className="grid min-h-72 min-w-64 place-items-center">
                                                <Spinner className="text-ink-muted" aria-label={t('ui.loading')} />
                                            </div>
                                        }
                                    >
                                        <DateCalendar
                                            selected={asDate(day)}
                                            latest={asDate(latest)}
                                            today={asDate(today)}
                                            onSelect={(choice) => {
                                                if (choice !== undefined) {
                                                    const pad = (n: number) => String(n).padStart(2, '0');
                                                    setPicked(`${choice.getFullYear()}-${pad(choice.getMonth() + 1)}-${pad(choice.getDate())}`);
                                                    setOpen(false);
                                                }
                                            }}
                                            arabic={locale === 'ar'}
                                        />
                                    </Suspense>
                                </PopoverContent>
                            </Popover>
                        </Field>
                    ) : null}
                </FieldSet>

                <div>
                    <ActionButton
                        type="button"
                        loading={busy}
                        onClick={() =>
                            router.post(
                                LIST,
                                { store, day: past ? day : '' },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                },
                            )
                        }
                        data-test="make-report"
                    >
                        {busy ? t('ops::admin_daily_reports.make.busy') : t('ops::admin_daily_reports.make.button')}
                    </ActionButton>
                </div>
            </CardContent>
        </Card>
    );
}

type EmailStore = { id: string | null; name: string; isOn: boolean; zone: string };

function DailyEmails({ emails, stores, allStores, zones }: Pick<DailyReportsScreenData, 'emails' | 'allStores' | 'zones'> & { stores: StoreChoiceData[] }) {
    const t = useTranslator();
    const rows: EmailStore[] = [
        ...(allStores ? [{ id: null, name: t('admin.store.all'), isOn: true, zone: zones[''] ?? 'UTC' }] : []),
        ...stores.map((store) => ({ id: store.id, name: store.name, isOn: store.isOn, zone: zones[store.id] ?? 'UTC' })),
    ];

    // No store at all: Make's one line has said so already.
    if (rows.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardContent className="grid gap-4">
                <h2 className="text-heading-20 text-ink">{t('ops::admin_daily_reports.email.title')}</h2>
                <ul className="grid divide-y divide-line">
                    {rows.map((row) => (
                        <EmailRow key={row.id ?? 'all'} store={row} email={emails.find((email) => email.storeId === row.id)} />
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

type Wish = { on: boolean; time: string };

function EmailRow({ store, email }: { store: EmailStore; email: DailyReportEmailData | undefined }) {
    const t = useTranslator();
    const { locale } = usePage<SharedProps>().props;
    const [on, setOn] = useState(email?.on ?? false);
    const [time, setTime] = useState(email?.sendAt ?? FIRST_TIME);
    const [saved, setSaved] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    // What the server holds, what the box says now, and the save out and the one waiting behind it.
    const confirmed = useRef<Wish>({ on: email?.on ?? false, time: email?.sendAt ?? FIRST_TIME });
    const latestTime = useRef(time);
    const sending = useRef(false);
    const waiting = useRef<Wish | null>(null);
    const id = `email-${store.id ?? 'all'}`;
    const rule = t('ops::admin_daily_reports.email.rule');

    const problem = (text: string): string | null => {
        if (!TIME.test(text)) {
            return rule;
        }

        return text === '00:00' ? t('ops::errors.daily_email_at_midnight.detail') : null;
    };

    const typed = problem(time);
    const shown = refusal ?? typed;

    latestTime.current = time;

    /**
     * One save at a time for a row, the latest wish waiting its turn: leaving the box and pressing the switch are two
     * saves a moment apart. Sent `async`, because a plain visit would cancel the one before it - another row's, or Make.
     */
    const send = (wanted: Wish) => {
        if (sending.current) {
            waiting.current = wanted;

            return;
        }

        let arrived = false;
        sending.current = true;
        setRefusal(null);
        router.post(
            EMAILS,
            { store: store.id ?? '', on: wanted.on, sendAt: wanted.time },
            {
                async: true,
                preserveScroll: true,
                only: ['emails'],
                onSuccess: () => {
                    arrived = true;
                    const moved = wanted.time !== confirmed.current.time;
                    confirmed.current = wanted;

                    if (moved && wanted.time === latestTime.current) {
                        setSaved(true);
                    }
                },
                onError: (errors) => {
                    arrived = true;
                    setRefusal(errors.email ?? errors.form ?? t('ops::admin_daily_reports.email.failed'));
                    setOn(confirmed.current.on);
                },
                onFinish: () => {
                    sending.current = false;

                    if (!arrived) {
                        setRefusal(t('ops::admin_daily_reports.email.failed'));
                        setOn(confirmed.current.on);
                    }

                    const next = waiting.current;
                    waiting.current = null;

                    if (next !== null) {
                        send(next);
                    }
                },
            },
        );
    };

    return (
        <li className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 py-3 first:pt-0 last:pb-0" data-test={id}>
            <div className="flex min-w-0 items-center gap-3">
                <Switch
                    id={`${id}-on`}
                    checked={on}
                    aria-label={t('ops::admin_daily_reports.email.on', { store: store.name })}
                    onCheckedChange={(next) => {
                        // A time that isn't complete keeps the switch from turning on, and the box says why.
                        if (next && typed !== null) {
                            document.getElementById(`${id}-time`)?.focus();

                            return;
                        }

                        setOn(next);
                        setSaved(false);
                        send({ on: next, time: typed === null ? time : confirmed.current.time });
                    }}
                    data-test={`${id}-switch`}
                />
                <div className="grid min-w-0 gap-0.5">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-label-14 text-ink">{store.name}</span>
                        {store.isOn ? null : <StoreOffBadge />}
                    </span>
                    {on && email?.nextDueAt ? (
                        <span className="tw-figure text-copy-13 text-ink-muted" data-test={`${id}-next`}>
                            {t('ops::admin_daily_reports.email.next', { time: formatMoment(email.nextDueAt, store.zone, locale) })}
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="grid justify-items-start gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        id={`${id}-time`}
                        dir="ltr"
                        inputMode="numeric"
                        autoComplete="off"
                        maxLength={5}
                        className="tw-figure w-24"
                        aria-label={t('ops::admin_daily_reports.email.time', { store: store.name })}
                        aria-invalid={shown !== null ? true : undefined}
                        aria-describedby={[`${id}-zone`, `${id}-rule`, shown !== null && shown !== rule ? `${id}-error` : null].filter(Boolean).join(' ')}
                        value={time}
                        onChange={(event) => {
                            setTime(toLatinDigits(event.target.value));
                            setSaved(false);
                            setRefusal(null);
                        }}
                        onBlur={() => {
                            if (typed === null && time !== confirmed.current.time) {
                                send({ on, time });
                            }
                        }}
                        data-test={`${id}-time`}
                    />
                    <span id={`${id}-zone`} className="text-copy-13 text-ink-muted">
                        {zoneName(store.zone, locale)}
                    </span>
                    <span role="status" className="text-copy-13 text-ink-muted" data-test={saved ? `${id}-saved` : undefined}>
                        {saved ? t('ops::admin_daily_reports.email.saved') : null}
                    </span>
                </div>
                <FieldDescription id={`${id}-rule`}>{rule}</FieldDescription>
                {shown !== null && shown !== rule ? (
                    <FieldError id={`${id}-error`} role="alert" data-test={`${id}-error`}>
                        {shown}
                    </FieldError>
                ) : null}
            </div>
        </li>
    );
}
