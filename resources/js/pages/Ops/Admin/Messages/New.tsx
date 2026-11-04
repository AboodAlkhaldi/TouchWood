import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { SelectField } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { CustomerPicker, HowItGoes, MessageBoxes, messageBoxes, SegmentPicker, SendToManyDialog, showRefusal, storeOption } from '@/components/ops/written-form';
import { useLeaveGuard } from '@/components/SaveBar';
import { Field, FieldError, FieldLabel, FieldLegend, FieldSet } from '@/components/ui/field';
import { NativeSelectOption } from '@/components/ui/native-select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { CustomerToMessageData, MessageFormPageData, SegmentChoiceData } from '@/types/generated/Modules/Ops/Presentation/Http/Resource';

/*
| O4 · New Message (ops.md §4.15; Q10, Q11, Q12, Q14): its own page, with no draft and nothing kept. To one customer it
| sends at once; to many it asks first, naming who, because it can't be recalled.
*/

const LIST = '/admin/messages';

type Audience = 'ONE' | 'STORE' | 'SEGMENT';

export default function New({ toOne, toMany, storeCode }: MessageFormPageData) {
    const t = useTranslator();
    const stores = [...toOne, ...toMany.filter((store) => !toOne.some((other) => other.code === store.code))];
    const allowedIn = (code: string): Audience[] => [...(toOne.some((store) => store.code === code) ? (['ONE'] as const) : []), ...(toMany.some((store) => store.code === code) ? (['STORE', 'SEGMENT'] as const) : [])];
    const form = useForm({
        store: storeCode,
        audience: allowedIn(storeCode)[0] ?? ('ONE' as Audience),
        customer: '',
        segment: '',
        title_ar: '',
        title_en: '',
        body_ar: '',
        body_en: '',
        by_bell: true,
        by_email: true,
    });
    const [customer, setCustomer] = useState<CustomerToMessageData | null>(null);
    const [segment, setSegment] = useState<SegmentChoiceData | null>(null);
    const [picking, setPicking] = useState(0);
    const [asking, setAsking] = useState(false);
    const checks = useChecks(messageBoxes(form.data, t));
    const allowed = allowedIn(form.data.store);
    const errors = form.errors as Record<string, string | undefined>;
    const store = stores.find((other) => other.code === form.data.store);
    // What would be lost: words typed, a customer or a segment chosen. A store or an audience alone is no message.
    const written = [form.data.title_ar, form.data.title_en, form.data.body_ar, form.data.body_en, form.data.customer, form.data.segment].some((value) => value !== '');
    const leave = useLeaveGuard(written, { description: t('ops::admin_written.messages.leave') });

    const send = () => {
        // Only what the audience names: the server refuses a store's message that names a customer.
        form.transform((data) => ({ ...data, customer: data.audience === 'ONE' ? data.customer : '', segment: data.audience === 'SEGMENT' ? data.segment : '' }));
        form.post(LIST, {
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

    const reason =
        checks.reason ??
        (form.data.audience === 'ONE' && customer === null
            ? t('ops::admin_written.customer.rule')
            : form.data.audience === 'SEGMENT' && segment === null
              ? t('ops::admin_written.segments.rule')
              : !form.data.by_bell && !form.data.by_email
                ? t('ops::admin_written.fields.channels_rule')
                : undefined);

    const words: Record<Audience, string> = {
        ONE: t('ops::admin_written.fields.one'),
        STORE: t('ops::admin_written.fields.store_all'),
        SEGMENT: t('ops::admin_written.fields.segment'),
    };

    return (
        <AdminLayout title={t('ops::admin_written.messages.new')} subtitle={t('ops::admin_written.messages.new_subtitle')} breadcrumbs={[{ label: t('ops::admin_written.messages.title'), href: `${LIST}?store=${encodeURIComponent(form.data.store)}` }]}>
            <form
                className="grid max-w-4xl gap-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    checks.submit(() => (form.data.audience === 'ONE' ? send() : setAsking(true)));
                }}
            >
                <FormError />

                {stores.length > 1 ? (
                    <SelectField
                        id="store"
                        label={t('ops::admin_written.fields.store')}
                        className="max-w-xs"
                        value={form.data.store}
                        onChange={(event) => {
                            const next = event.target.value;
                            // Changing the store clears the customer and the segment: they are that store's.
                            setCustomer(null);
                            setSegment(null);
                            setPicking((before) => before + 1);
                            form.setData((data) => ({ ...data, store: next, customer: '', segment: '', audience: allowedIn(next).includes(data.audience) ? data.audience : (allowedIn(next)[0] ?? data.audience) }));
                        }}
                        data-test="store"
                    >
                        {stores.map((other) => (
                            <NativeSelectOption key={other.code} value={other.code}>
                                {storeOption(other, t)}
                            </NativeSelectOption>
                        ))}
                    </SelectField>
                ) : null}

                <FieldSet className="gap-3" data-test="who">
                    <FieldLegend variant="label" className="text-label-14 text-ink">
                        {t('ops::admin_written.fields.who')}
                    </FieldLegend>
                    {allowed.length > 1 ? (
                        <RadioGroup
                            value={form.data.audience}
                            onValueChange={(next) => {
                                form.setData('audience', next as Audience);
                                form.clearErrors('audience', 'customer');
                            }}
                        >
                            {allowed.map((audience) => (
                                <Field key={audience} orientation="horizontal">
                                    <RadioGroupItem id={`who-${audience}`} value={audience} data-test={`who-${audience}`} />
                                    <FieldLabel htmlFor={`who-${audience}`} className="font-normal">
                                        {words[audience]}
                                    </FieldLabel>
                                </Field>
                            ))}
                        </RadioGroup>
                    ) : (
                        <p className="text-copy-14 text-ink" data-test="who-line">
                            {words[form.data.audience]}
                        </p>
                    )}
                    {errors.audience ? (
                        <FieldError role="alert" data-test="audience-error">
                            {errors.audience}
                        </FieldError>
                    ) : null}

                    {form.data.audience === 'ONE' ? (
                        <CustomerPicker
                            key={picking}
                            store={form.data.store}
                            chosen={customer}
                            onChoose={(next) => {
                                setCustomer(next);
                                form.setData('customer', next?.id ?? '');
                                form.clearErrors('customer');
                            }}
                            error={errors.customer}
                        />
                    ) : null}
                    {form.data.audience === 'SEGMENT' ? (
                        <SegmentPicker
                            key={`${form.data.store}-${picking}`}
                            path={`${LIST}/segments`}
                            store={form.data.store}
                            chosen={segment}
                            onChoose={(next) => {
                                setSegment(next);
                                form.setData('segment', next.id);
                                form.clearErrors('audience');
                            }}
                        />
                    ) : null}
                </FieldSet>

                <MessageBoxes form={form} checks={checks} />

                <HowItGoes
                    bell={form.data.by_bell}
                    email={form.data.by_email}
                    onBell={(on) => form.setData('by_bell', on)}
                    onEmail={(on) => form.setData('by_email', on)}
                    emailNote={form.data.audience === 'ONE' ? t('ops::admin_written.fields.email_one') : t('ops::admin_written.fields.email_many')}
                    error={errors.channels}
                />

                <div>
                    <ActionButton type="submit" loading={form.processing} disabledReason={reason} data-test="send">
                        {t('ops::admin_written.messages.send')}
                    </ActionButton>
                </div>
            </form>

            {asking && store !== undefined ? (
                <SendToManyDialog
                    store={store}
                    segment={form.data.audience === 'SEGMENT' ? segment : null}
                    byBell={form.data.by_bell}
                    label={t('ops::admin_written.messages.send')}
                    busy={form.processing}
                    onClose={() => setAsking(false)}
                    onSend={send}
                />
            ) : null}
            {leave}
        </AdminLayout>
    );
}
