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
import type { BoxData } from '@/types/generated/Modules/Shipping/Presentation/Http/Resource';
import { B, BOXES, gramsOf, inside, kgOf, mmOf } from './parts';

/*
| X2 · Add Box / Edit Box (shipping.md §4.1 part 3, B3–B6): a module of its own, loaded when it is first opened.
| The name; the inner sizes in whole mm (B5); the most weight in kg, up to three decimals, sent in grams (B4);
| and, as they are typed, what fits after the padding (B6). Every box checks itself with step 5's rules.
*/

type BoxForm = {
    store: string;
    name: string;
    length: string;
    width: string;
    height: string;
    weight: string;
    active: boolean;
};

function initial(box: BoxData | null, storeCode: string): BoxForm {
    return {
        store: storeCode,
        name: box?.name ?? '',
        length: box === null ? '' : String(box.lengthMm),
        width: box === null ? '' : String(box.widthMm),
        height: box === null ? '' : String(box.heightMm),
        weight: box === null ? '' : kgOf(box.maxWeightG),
        active: true,
    };
}

export function BoxDialog({
    box,
    storeCode,
    paddingMm,
    open,
    onOpenChange,
    returnFocusTo,
}: {
    box: BoxData | null;
    storeCode: string;
    paddingMm: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocusTo?: RefObject<HTMLElement | null>;
}) {
    const t = useTranslator();
    const form = useForm<BoxForm>(initial(box, storeCode));

    useEffect(() => {
        if (open) {
            form.setDefaults(initial(box, storeCode));
            form.reset();
            form.clearErrors();
        }
    }, [open, box?.id]);

    const size = { required: true, own: (text: string) => (mmOf(text) === null ? t(`${B}.dialog.size_rule`) : null) };
    const checks = useChecks([
        { id: 'box-name', label: t(`${B}.dialog.name`), value: form.data.name, rules: { required: true, length: { max: 100 } } },
        { id: 'box-length', label: t(`${B}.dialog.length`), value: form.data.length, rules: size },
        { id: 'box-width', label: t(`${B}.dialog.width`), value: form.data.width, rules: size },
        { id: 'box-height', label: t(`${B}.dialog.height`), value: form.data.height, rules: size },
        { id: 'box-weight', label: t(`${B}.dialog.weight`), value: form.data.weight, rules: { required: true, own: (text) => (gramsOf(text) === null ? t(`${B}.dialog.weight_rule`) : null) } },
    ]);
    // The server names its refusals by its own fields (length_mm, max_weight_g).
    const errors = form.errors as Record<string, string | undefined>;
    const sides = [mmOf(form.data.length), mmOf(form.data.width), mmOf(form.data.height)];
    const room = sides.every((side) => side !== null) ? inside(sides as [number, number, number], paddingMm) : undefined;

    function submit() {
        checks.submit(() => {
            // The server's names: the sizes in mm, the weight in grams.
            form.transform((data) => ({
                store: data.store,
                name: data.name,
                length_mm: data.length,
                width_mm: data.width,
                height_mm: data.height,
                max_weight_g: String(gramsOf(data.weight) ?? ''),
                active: data.active,
            }));
            form.post(box === null ? BOXES : `${BOXES}/${box.id}`, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
        });
    }

    const number = (key: 'length' | 'width' | 'height', id: string, error?: string) => (
        <TextField
            id={id}
            dir="ltr"
            inputMode="numeric"
            inputClassName="tw-figure"
            label={`${t(`${B}.dialog.${key}`)} (${t(`${B}.dialog.mm`)})`}
            value={form.data[key]}
            check={checks.box(id, error)}
            onChange={(event) => form.setData(key, toLatinDigits(event.target.value))}
            data-test={id}
        />
    );

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            returnFocusTo={returnFocusTo}
            title={box === null ? t(`${B}.list.add`) : t(`${B}.dialog.edit_title`)}
            description={box === null ? t(`${B}.dialog.add_body`) : t(`${B}.dialog.edit_body`)}
            busy={form.processing}
            confirm={
                <ActionButton loading={form.processing} disabledReason={checks.reason} onClick={submit} data-test="confirm-box">
                    {box === null ? t(`${B}.list.add`) : t(`${B}.dialog.save`)}
                </ActionButton>
            }
        >
            <div className="grid gap-4">
                <TextField id="box-name" label={t(`${B}.dialog.name`)} value={form.data.name} check={checks.box('box-name', form.errors.name)} onChange={(event) => form.setData('name', event.target.value)} data-test="box-name" />
                <div className="grid gap-4 sm:grid-cols-3">
                    {number('length', 'box-length', errors.length_mm)}
                    {number('width', 'box-width', errors.width_mm)}
                    {number('height', 'box-height', errors.height_mm)}
                </div>
                {room !== undefined ? (
                    <p className="text-copy-13 text-ink-muted" role="status" data-test="box-inside">
                        {room === null ? t(`${B}.list.too_small`) : t(`${B}.dialog.inside`, { mm: paddingMm, l: room[0], w: room[1], h: room[2] })}
                    </p>
                ) : null}
                <TextField
                    id="box-weight"
                    dir="ltr"
                    inputMode="decimal"
                    inputClassName="tw-figure"
                    label={`${t(`${B}.dialog.weight`)} (${t(`${B}.dialog.kg`)})`}
                    helper={t(`${B}.dialog.weight_hint`)}
                    value={form.data.weight}
                    check={checks.box('box-weight', errors.max_weight_g)}
                    onChange={(event) => form.setData('weight', toLatinDigits(event.target.value))}
                    data-test="box-weight"
                />
                {box === null ? (
                    <Field orientation="horizontal">
                        <FieldContent>
                            <FieldLabel htmlFor="box-active" className="text-label-14 text-ink">
                                {t(`${B}.dialog.active`)}
                            </FieldLabel>
                            <FieldDescription id="box-active-helper" className="text-copy-13 text-ink-muted">
                                {t(`${B}.dialog.active_hint`)}
                            </FieldDescription>
                        </FieldContent>
                        <Switch id="box-active" checked={form.data.active} onCheckedChange={(on) => form.setData('active', on)} aria-describedby="box-active-helper" className="data-[state=unchecked]:bg-ink-subtle" data-test="box-active" />
                    </Field>
                ) : null}
            </div>
        </PanelDialog>
    );
}
