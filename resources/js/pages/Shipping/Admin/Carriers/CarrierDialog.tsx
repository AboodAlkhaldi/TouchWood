import { useEffect, type RefObject } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import { ImageField } from '@/pages/Catalog/Admin/ImageField';
import type { CarrierData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import { C, LIST, trackingExample, trackingProblem } from './parts';

/*
| C2 · Add Carrier / Edit Carrier (shipping.md §4.1 part 1, Q3, Q8, Q9, Q10): a module of its own, loaded
| when the carriers screens first open it, so their pages stay within their budget (frontend.md §5).
| Every box checks itself as it is typed with step 3's rules: names 1-100 characters in each language,
| both required; the phone as every phone (E.164, Latin digits - §9.4 #2); the website and the tracking
| link https only, at most 500 characters, the link with exactly one {number} (§1.3, §9.4 #6) - and the
| link shows, as it is typed, what a tracking number would open.
*/

type CarrierForm = {
    store: string;
    country: string;
    name_ar: string;
    name_en: string;
    phone: string;
    website: string;
    tracking_pattern: string;
    active: boolean;
    logo_media_id: string;
    logo: File | null;
    remove_logo: boolean;
};

function initial(carrier: CarrierData | null, storeCode: string, countryCode: string): CarrierForm {
    return {
        store: storeCode,
        country: countryCode,
        name_ar: carrier?.nameAr ?? '',
        name_en: carrier?.nameEn ?? '',
        phone: carrier?.phone ?? '',
        website: carrier?.website ?? '',
        tracking_pattern: carrier?.trackingPattern ?? '',
        active: true,
        logo_media_id: carrier?.logoMediaId ?? '',
        logo: null,
        remove_logo: false,
    };
}

export function CarrierDialog({
    carrier,
    countryCode,
    countryName,
    storeCode,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    carrier: CarrierData | null;
    countryCode: string;
    countryName: string;
    storeCode: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<CarrierForm>(initial(carrier, storeCode, countryCode));

    useEffect(() => {
        if (open) {
            form.setDefaults(initial(carrier, storeCode, countryCode));
            form.reset();
            form.clearErrors();
        }
    }, [open, carrier?.id]);

    const name = { required: true, length: { max: 100 } };
    const web = { length: { max: 500 } };
    const checks = useChecks([
        { id: 'carrier-name-ar', label: t(`${C}.field.name_ar`), value: form.data.name_ar, rules: name },
        { id: 'carrier-name-en', label: t(`${C}.field.name_en`), value: form.data.name_en, rules: name },
        { id: 'carrier-phone', label: t(`${C}.field.phone`), value: form.data.phone, rules: { phone: true } },
        {
            id: 'carrier-website',
            label: t(`${C}.field.website`),
            value: form.data.website,
            rules: { ...web, own: (text) => (text.trim() === '' || /^https:\/\//i.test(text.trim()) ? null : t(`${C}.tracking.https`)) },
        },
        { id: 'carrier-tracking', label: t(`${C}.field.tracking`), value: form.data.tracking_pattern, rules: { ...web, own: (text) => trackingProblem(text, t) } },
    ]);
    const example = trackingExample(form.data.tracking_pattern);

    function submit() {
        // Sent as multipart only when a logo file is attached (Inertia does that by itself).
        checks.submit(() =>
            form.post(carrier === null ? LIST : `${LIST}/${carrier.id}`, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            }),
        );
    }

    return (
        <PanelDialog
            wide
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={carrier === null ? t(`${C}.add`) : t(`${C}.edit_title`)}
            description={carrier === null ? t(`${C}.add_body`) : t(`${C}.edit_body`)}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-carrier">
                    {carrier === null ? t(`${C}.add`) : t(`${C}.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <div className="grid gap-1">
                    <span className="text-label-14 text-ink">{t(`${C}.field.country`)}</span>
                    <span className="text-copy-14 text-ink-muted" data-test="carrier-country">
                        {countryName}
                    </span>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField id="carrier-name-ar" dir="rtl" label={t(`${C}.field.name_ar`)} value={form.data.name_ar} check={checks.box('carrier-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="carrier-name-ar" />
                    <TextField id="carrier-name-en" dir="ltr" label={t(`${C}.field.name_en`)} value={form.data.name_en} check={checks.box('carrier-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="carrier-name-en" />
                </div>

                <ImageField
                    id="carrier-logo"
                    label={t(`${C}.field.logo`)}
                    held={(carrier?.logoMediaId ?? null) !== null}
                    current={carrier?.logo ?? null}
                    onFile={(file) => form.setData('logo', file)}
                    remove={form.data.remove_logo}
                    onRemove={(remove) => form.setData('remove_logo', remove)}
                    error={form.errors.logo_media_id}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        id="carrier-phone"
                        type="tel"
                        dir="ltr"
                        autoComplete="off"
                        inputClassName="tw-figure"
                        label={t(`${C}.field.phone`)}
                        helper={t(`${C}.field.phone_hint`)}
                        value={form.data.phone}
                        check={checks.box('carrier-phone', form.errors.phone)}
                        onChange={(event) => form.setData('phone', toLatinDigits(event.target.value))}
                        data-test="carrier-phone"
                    />
                    <TextField
                        id="carrier-website"
                        type="url"
                        dir="ltr"
                        label={t(`${C}.field.website`)}
                        helper={t(`${C}.field.website_hint`)}
                        value={form.data.website}
                        check={checks.box('carrier-website', form.errors.website)}
                        onChange={(event) => form.setData('website', event.target.value)}
                        data-test="carrier-website"
                    />
                </div>

                <TextField
                    id="carrier-tracking"
                    dir="ltr"
                    label={t(`${C}.field.tracking`)}
                    helper={
                        <>
                            {t(`${C}.field.tracking_hint`)}
                            {example !== null ? (
                                <span className="mt-1 block break-all text-ink" data-test="carrier-tracking-example">
                                    {t(`${C}.tracking.example`, { url: example })}
                                </span>
                            ) : null}
                        </>
                    }
                    value={form.data.tracking_pattern}
                    check={checks.box('carrier-tracking', form.errors.tracking_pattern)}
                    onChange={(event) => form.setData('tracking_pattern', event.target.value)}
                    data-test="carrier-tracking"
                />

                {carrier === null ? (
                    // One setting on its own is a switch, its sentence tied to it (Geist's Toggle).
                    <Field orientation="horizontal">
                        <FieldContent>
                            <FieldLabel htmlFor="carrier-active" className="text-label-14 text-ink">
                                {t(`${C}.field.active`)}
                            </FieldLabel>
                            <FieldDescription id="carrier-active-helper" className="text-copy-13 text-ink-muted">
                                {t(`${C}.field.active_hint`)}
                            </FieldDescription>
                        </FieldContent>
                        <Switch
                            id="carrier-active"
                            checked={form.data.active}
                            onCheckedChange={(on) => form.setData('active', on)}
                            aria-describedby="carrier-active-helper"
                            className="data-[state=unchecked]:bg-ink-subtle"
                            data-test="carrier-active"
                        />
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
    );
}
