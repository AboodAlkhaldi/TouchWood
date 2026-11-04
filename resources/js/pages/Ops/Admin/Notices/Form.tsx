import { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { DateTimeField } from '@/components/DateTimeField';
import { SelectField, TextField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { Note } from '@/components/Note';
import { HowItGoes, MessageBoxes, messageBoxes, SegmentPicker, SendToManyDialog, showRefusal, storeOption } from '@/components/ops/written-form';
import { useLeaveGuard } from '@/components/SaveBar';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AdminLayout } from '@/layouts/AdminLayout';
import { toLatinDigits } from '@/lib/digits';
import { momentBox, noMoment, type MomentInput } from '@/lib/moments';
import { readNoticeLink } from '@/lib/notice-link';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { NoticeFormPageData, SegmentChoiceData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';
import type { SharedProps } from '@/types/page';

/*
| O6 · New and Edit Notice (ops.md §4.15; Q10, Q11, Q12, Q17). The form always sends the audience and every other field,
| `send_at` included, because a change replaces the whole notice and a change without `send_at` would send it at once.
| Now asks first, naming who; a scheduled save doesn't ask, and the toast says when it goes.
*/

const LIST = '/admin/notices';

function sendingAt(saved: string | null | undefined): MomentInput {
    const [date, time] = (saved ?? '').split(' ');

    return date === undefined || date === '' || time === undefined ? noMoment() : { none: false, date, time, typedAt: 0 };
}

export default function Form({ stores, storeCode, notice }: NoticeFormPageData) {
    const t = useTranslator();
    const { errors: pageErrors } = usePage<SharedProps>().props;
    // A deleted segment has no name: the send waits for another choice.
    const hadSegment = notice !== null && notice.segmentId !== null && notice.segmentNameAr !== null;
    const form = useForm({
        store: storeCode,
        segment: hadSegment ? (notice.segmentId as string) : '',
        title_ar: notice?.titleAr ?? '',
        title_en: notice?.titleEn ?? '',
        body_ar: notice?.bodyAr ?? '',
        body_en: notice?.bodyEn ?? '',
        link: notice?.link ?? '',
        by_bell: notice?.byBell ?? true,
        by_email: notice?.byEmail ?? true,
    });
    const [when, setWhen] = useState<MomentInput>(() => sendingAt(notice?.sendAtLocal));
    const [whenTouched, setWhenTouched] = useState(false);
    const [toSegment, setToSegment] = useState(notice !== null && notice.segmentId !== null);
    const [segment, setSegment] = useState<SegmentChoiceData | null>(
        hadSegment ? { id: notice.segmentId as string, nameAr: notice.segmentNameAr as string, nameEn: notice.segmentNameEn as string, memberCount: notice.segmentMemberCount ?? 0 } : null,
    );
    const segmentDeleted = notice !== null && notice.segmentId !== null && notice.segmentNameAr === null && segment === null;
    const [picking, setPicking] = useState(0);
    const [asking, setAsking] = useState(false);
    const store = stores.find((other) => other.code === form.data.store) ?? stores[0];
    const zone = store?.timezone ?? 'UTC';
    const origin = typeof window === 'undefined' ? '' : window.location.origin;
    const link = readNoticeLink(
        form.data.link,
        form.data.store,
        origin,
        stores.map((other) => other.code),
    );
    const checks = useChecks([
        ...messageBoxes(form.data, t),
        {
            id: 'link',
            label: t('ops::admin_written.notices.link'),
            value: form.data.link,
            rules: {
                own: () => (link.problem === 'other_site' ? 'ops::admin_written.notices.link_other_site' : link.problem === 'other_store' ? 'ops::admin_written.notices.link_other_store' : link.problem === 'rule' ? 'ops::admin_written.notices.link_rule' : null),
            },
        },
        // Judged against the present, not the minute it was typed in: a notice's time must still be to come when it is sent.
        momentBox({ ...when, typedAt: 0 }, { id: 'when', label: t('ops::admin_written.notices.when'), kind: 'start', zone }),
    ]);
    const errors = form.errors as Record<string, string | undefined>;
    // The server's refusal has this name; the form sends no field of it.
    const clearAudience = () => (form.clearErrors as unknown as (field: string) => void)('audience');
    const started = pageErrors.started !== undefined;
    const leave = useLeaveGuard(form.isDirty || whenTouched || toSegment !== (notice?.segmentId != null), { description: t('ops::admin_written.notices.leave') });
    const now = when.none;

    const send = () => {
        // Never to every customer because a segment was lost on the way.
        if (toSegment && form.data.segment === '') {
            return;
        }

        form.transform((data) => ({ ...data, segment: toSegment ? data.segment : '', link: link.part, send_at: now ? '' : `${when.date} ${when.time.trim()}` }));
        form.post(notice === null ? LIST : `${LIST}/${notice.id}`, {
            preserveScroll: 'errors',
            onError: (answer) => {
                setAsking(false);
                showRefusal(answer as Record<string, string>);

                if ((answer as Record<string, string>).segment_gone !== undefined) {
                    setSegment(null);
                    form.setData('segment', '');
                    setPicking((before) => before + 1);
                }
            },
        });
    };

    const reason = checks.reason ?? (toSegment && form.data.segment === '' ? t('ops::admin_written.segments.rule') : !form.data.by_bell && !form.data.by_email ? t('ops::admin_written.fields.channels_rule') : undefined);
    const button = notice === null ? (now ? t('ops::admin_written.notices.send') : t('ops::admin_written.notices.schedule')) : now ? t('ops::admin_written.notices.send_now') : t('ops::admin_written.notices.save');
    const backToList = `${LIST}?store=${encodeURIComponent(form.data.store)}`;

    return (
        <AdminLayout title={notice === null ? t('ops::admin_written.notices.new') : t('ops::admin_written.notices.edit')} breadcrumbs={[{ label: t('ops::admin_written.notices.title'), href: backToList }]}>
            <form
                className="grid max-w-4xl gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => (now ? setAsking(true) : send()));
                }}
            >
                <FormError />
                {started ? (
                    <div>
                        <Button asChild variant="outline">
                            <Link href={backToList} data-test="back-to-list">
                                {t('ops::admin_written.notices.back_to_list')}
                            </Link>
                        </Button>
                    </div>
                ) : null}

                {notice === null && stores.length > 1 ? (
                    <SelectField
                        id="store"
                        label={t('ops::admin_written.fields.store')}
                        className="max-w-xs"
                        value={form.data.store}
                        onChange={(event) => {
                            setSegment(null);
                            setPicking((before) => before + 1);
                            form.setData((data) => ({ ...data, store: event.target.value, segment: '' }));
                        }}
                        data-test="store"
                    >
                        {stores.map((other) => (
                            <NativeSelectOption key={other.code} value={other.code}>
                                {storeOption(other, t)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                ) : (
                    <p className="text-copy-14 text-ink" data-test="store-line">
                        <span className="text-ink-muted">{`${t('ops::admin_written.fields.store')}: `}</span>
                        {store === undefined ? null : storeOption(store, t)}
                    </p>
                )}

                <FieldSet className="gap-3" data-test="who">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('ops::admin_written.fields.who')}
                    </FieldLegend>
                    <RadioGroup
                        value={toSegment ? 'SEGMENT' : 'STORE'}
                        onValueChange={(next) => {
                            setToSegment(next === 'SEGMENT');
                            clearAudience();
                        }}
                    >
                        <Field orientation="horizontal">
                            <RadioGroupItem id="who-STORE" value="STORE" data-test="who-STORE" />
                            <FieldLabel htmlFor="who-STORE" className="font-normal">
                                {t('ops::admin_written.fields.store_all')}
                            </FieldLabel>
                        </Field>
                        <Field orientation="horizontal">
                            <RadioGroupItem id="who-SEGMENT" value="SEGMENT" data-test="who-SEGMENT" />
                            <FieldLabel htmlFor="who-SEGMENT" className="font-normal">
                                {t('ops::admin_written.fields.segment')}
                            </FieldLabel>
                        </Field>
                    </RadioGroup>
                    {errors.audience ? (
                        <FieldError role="alert" data-test="audience-error">
                            {errors.audience}
                        </FieldError>
                    ) : null}
                    {toSegment ? (
                        <>
                            {segmentDeleted ? (
                                <Note variant="warning" data-test="segment-deleted">
                                    {t('ops::admin_written.notices.segment_deleted')}
                                </Note>
                            ) : null}
                            <SegmentPicker
                                key={`${form.data.store}-${picking}`}
                                path={`${LIST}/segments`}
                                store={form.data.store}
                                chosen={segment}
                                onChoose={(next) => {
                                    setSegment(next);
                                    form.setData('segment', next.id);
                                    clearAudience();
                                }}
                            />
                        </>
                    ) : null}
                </FieldSet>

                <MessageBoxes form={form} checks={checks} />

                <TextField
                    id="link"
                    dir="ltr"
                    label={t('ops::admin_written.notices.link')}
                    helper={link.part === '' ? t('ops::admin_written.notices.link_helper') : t('ops::admin_written.notices.link_part', { part: link.part })}
                    check={checks.box('link', form.errors.link)}
                    value={form.data.link}
                    onChange={(event) => form.setData('link', toLatinDigits(event.target.value))}
                    data-test="link"
                />

                <HowItGoes
                    bell={form.data.by_bell}
                    email={form.data.by_email}
                    onBell={(on) => form.setData('by_bell', on)}
                    onEmail={(on) => form.setData('by_email', on)}
                    emailNote={t('ops::admin_written.fields.email_many')}
                    error={errors.channels}
                />

                <DateTimeField
                    id="when"
                    label={t('ops::admin_written.notices.when')}
                    kind="start"
                    value={when}
                    onChange={(next) => {
                        setWhen(next);
                        setWhenTouched(true);
                    }}
                    zone={zone}
                    refusesPast
                    check={checks.box('when-time', errors.send_at)}
                />

                <div>
                    <ActionButton type="submit" loading={form.processing} disabledReason={reason} data-test="send">
                        {button}
                    </ActionButton>
                </div>
            </form>

            {asking && store !== undefined ? (
                <SendToManyDialog store={store} segment={toSegment ? (segment ?? null) : null} byBell={form.data.by_bell} label={button} busy={form.processing} onClose={() => setAsking(false)} onSend={send} />
            ) : null}
            {leave}
        </AdminLayout>
    );
}
