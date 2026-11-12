import { type RefObject, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { ActionButton } from '@/components/ActionButton';
import { TextField } from '@/components/Fields';
import { PanelDialog } from '@/components/PanelDialog';
import { Badge } from '@/components/ui/badge';
import { FieldError, FieldLegend, FieldSet } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslator } from '@/lib/t';
import { tone } from '@/lib/tones';
import { useChecks } from '@/lib/use-checks';
import type { LabelData } from '@/types/generated/Modules/Catalog/Presentation/Http/Resource';
import { nameIn, useLocale } from '../parts';
import { MEANINGS, meaningOf, toneOf, type Meaning } from './meanings';

/*
| The label's add and edit dialog (catalog.md §1.8, §4.4 S5): a module of its own, loaded when the labels screen first
| opens it, as the brands' dialog is, so the page stays within its budget with its phone cards (frontend.md §5).
*/

type LabelForm = { name_ar: string; name_en: string; meaning: Meaning; subtle: boolean };

export function LabelDialog({
    label,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    label: LabelData | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const locale = useLocale();
    const initial = (): LabelForm => {
        const look = meaningOf(label?.tone ?? 'gray');

        return { name_ar: label?.nameAr ?? '', name_en: label?.nameEn ?? '', meaning: look.meaning, subtle: label === null ? true : look.subtle };
    };
    const form = useForm<LabelForm>(initial());
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.setDefaults(initial());
            form.reset();
            form.clearErrors();
        }
    }, [open, label?.id]);

    const title = label === null ? t('catalog::admin_labels.add') : t('catalog::admin_labels.edit_title');
    const preview = nameIn(locale, form.data.name_ar, form.data.name_en) || t('catalog::admin_labels.preview_empty');
    // Each box as typed (frontend.md §1.7), with the domain's rules: names of up to 30 characters
    // (Label::NAME_MAX) of one or two words - what the spaces separate (Label::checkWords, amendment
    // 1(f)). A new label goes last; it has no place to type (amendment 16(d)).
    const name = { required: true, length: { max: 30 }, format: { pattern: /^\S+(\s+\S+)?$/u, key: 'catalog::admin_labels.check.words' } };
    const checks = useChecks([
        { id: 'label-name-ar', label: t('catalog::admin.field.name_ar'), value: form.data.name_ar, rules: name },
        { id: 'label-name-en', label: t('catalog::admin.field.name_en'), value: form.data.name_en, rules: name },
    ]);

    function submit() {
        form.transform((data) => ({ name_ar: data.name_ar, name_en: data.name_en, tone: toneOf(data.meaning, data.subtle) }));
        checks.submit(() => form.post(label === null ? '/admin/labels' : `/admin/labels/${label.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) }));
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={title}
            description={t('catalog::admin_labels.body')}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-label">
                    {label === null ? title : t('catalog::admin_labels.save')}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="label-name-ar" dir="rtl" label={t('catalog::admin.field.name_ar')} helper={t('catalog::admin_labels.name_helper')} value={form.data.name_ar} check={checks.box('label-name-ar', form.errors.name_ar)} onChange={(event) => form.setData('name_ar', event.target.value)} data-test="label-name-ar" />
                <TextField id="label-name-en" dir="ltr" label={t('catalog::admin.field.name_en')} helper={t('catalog::admin_labels.name_helper')} value={form.data.name_en} check={checks.box('label-name-en', form.errors.name_en)} onChange={(event) => form.setData('name_en', event.target.value)} data-test="label-name-en" />

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_labels.column.meaning')}</FieldLegend>
                    <RadioGroup value={form.data.meaning} onValueChange={(value) => form.setData('meaning', value as Meaning)} className="grid gap-2 sm:grid-cols-2">
                        {MEANINGS.map(([meaning]) => (
                            <div key={meaning} className="flex items-center gap-2">
                                <RadioGroupItem id={`meaning-${meaning}`} value={meaning} data-test={`meaning-${meaning}`} />
                                <Label htmlFor={`meaning-${meaning}`} className="text-copy-14">
                                    {t(`catalog::admin_labels.meaning.${meaning}`)}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                    {errors.tone ? <FieldError>{errors.tone}</FieldError> : null}
                </FieldSet>

                <FieldSet>
                    <FieldLegend className="text-label-14 text-ink">{t('catalog::admin_labels.strength.title')}</FieldLegend>
                    <RadioGroup value={form.data.subtle ? 'subtle' : 'strong'} onValueChange={(value) => form.setData('subtle', value === 'subtle')} className="flex gap-4">
                        {(['strong', 'subtle'] as const).map((strength) => (
                            <div key={strength} className="flex items-center gap-2">
                                <RadioGroupItem id={`strength-${strength}`} value={strength} data-test={`strength-${strength}`} />
                                <Label htmlFor={`strength-${strength}`} className="text-copy-14">
                                    {t(`catalog::admin_labels.strength.${strength}`)}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                </FieldSet>

                <p className="flex items-center gap-2 text-copy-13 text-ink-muted">
                    {t('catalog::admin_labels.preview')}
                    <Badge className={tone(toneOf(form.data.meaning, form.data.subtle))} data-test="label-preview">
                        {preview}
                    </Badge>
                </p>
            </div>
        </PanelDialog>
    );
}
