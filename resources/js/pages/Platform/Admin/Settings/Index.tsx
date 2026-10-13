import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { ActionButton } from '@/components/ActionButton';
import { describedBy, Messages } from '@/components/Fields';
import { FormError } from '@/components/FormError';
import { StoreFilter } from '@/components/StoreFilter';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { Rules } from '@/lib/checks';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import { useChecks } from '@/lib/use-checks';
import type { SettingRowData, SettingsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E4 - the settings (frontend.md §3.5), a module's settings one of shadcn's Cards, each setting a
| shadcn Field (§1.11).
|
| Every declared setting this person may change, grouped by the module that declared it. **A row
| they may not change is not here at all**: each setting carries its own permission, so that is one
| answer per row rather than one for the page - which is why Access's numbers sit in a single Access
| section although a store's own settings and the staff security ones carry different permissions
| (owner, 2026-09-22).
|
| A store setting applies to the store chosen in the page's own store filter (frontend.md §2.2,
| platform.md §9.10; the owner, 2026-10-06), and each such row says which store that is; its save
| sends that store. A global one is the same value everywhere, and only somebody who reaches every
| store may change it.
|
| A sensitive setting never shows its value (platform.md §1.3) - not even to the person changing it.
| The field is empty, and saying nothing leaves it as it was.
|
| Each setting saves on its own: one button for the page would make a person who meant to change
| one number answer for every other number on the screen. Geist's rules for the two kinds (owner,
| 2026-10-04, from pictures - replacing the read-until-Edit of 2026-09-24):
| - a yes-or-no setting is Geist's Toggle, which saves the moment it flips and says so in a toast;
|   it shows the server's answer, never a guess, and is out of reach while its save is on its way;
| - a number or a text is Geist's Fieldset way: the field is open, and its Save Setting is always
|   there, out of reach and saying why until the value changes; Cancel then appears, to put the
|   stored value back.
*/

type Props = SettingsPage;

export default function Index({ groups, storeName, storeCode, stores }: Props) {
    const t = useTranslator();
    const store: Store = { name: storeName, code: storeCode };

    return (
        <AdminLayout title={t('platform::admin_settings.title')} subtitle={t('platform::admin_settings.subtitle')}>
            <div className="grid gap-6">
                <FormError />

                <StoreFilter stores={stores} value={storeCode} className="max-w-xs" />

                {groups.length === 0 ? (
                    <Empty className="material-base">
                        <EmptyHeader>
                            <EmptyTitle className="text-heading-16 text-ink">{t('platform::admin_settings.none_title')}</EmptyTitle>
                            <EmptyDescription className="text-copy-14 text-ink-muted">{t('platform::admin_settings.none')}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    groups.map((group) => (
                        <Card key={group.module} className="material-base gap-0 overflow-hidden border-0 py-0" data-test={`settings-${group.module}`}>
                            <CardHeader className="border-b border-line px-6 py-4 [.border-b]:pb-4">
                                <CardTitle>
                                    <h2 className="text-heading-16 text-ink">{group.label}</h2>
                                </CardTitle>
                                {/* What the module's settings add up to in this store (platform.md §1.3). */}
                                {group.line === null ? null : <CardDescription className="text-copy-13 text-ink-muted">{group.line}</CardDescription>}
                            </CardHeader>
                            <CardContent className="px-0">
                                <ul className="divide-y divide-line">
                                    {group.settings.map((setting) => (
                                        <li key={setting.key} className="px-6 py-4">
                                            {setting.type === 'BOOLEAN' ? <Toggle setting={setting} store={store} /> : <Value setting={setting} store={store} />}
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
        </AdminLayout>
    );
}

/** The store a per-store setting belongs to, as the filter chose it. */
type Store = { name: string | null; code: string | null };

/** What a save sends: the value, and for a store's own setting, which store (platform.md §9.10). */
function sent(setting: SettingRowData, store: Store, value: string): { value: string; store?: string } {
    return setting.scope === 'STORE' && store.code !== null ? { value, store: store.code } : { value };
}

/** The line under a setting's name: where it applies, whether it is secret, its default. */
function Scope({ setting, store }: { setting: SettingRowData; store: Store }) {
    const t = useTranslator();

    return (
        <>
            {setting.scope === 'STORE' && store.name !== null ? t('platform::admin_settings.in_store', { store: store.name }) : t('platform::admin_settings.everywhere')}
            {setting.sensitive ? ` · ${t('platform::admin_settings.sensitive')}` : ''}
            {/* A default that is empty means "not set yet": there is no value in force to name (owner, 2026-09-29). */}
            {!setting.sensitive && setting.isDefault && String(setting.default ?? '') !== ''
                ? ` · ${t('platform::admin_settings.is_default', { value: String(setting.default ?? '') })}`
                : ''}
        </>
    );
}

/** A yes-or-no setting: saved the moment it flips (Geist's Toggle). */
function Toggle({ setting, store }: { setting: SettingRowData; store: Store }) {
    const t = useTranslator();
    const [saving, setSaving] = useState(false);
    const [tip, setTip] = useState(false);
    const [error, setError] = useState<string | undefined>(undefined);
    const on = setting.value === true || setting.value === 'true' || setting.value === '1' || setting.value === 1;
    const id = `setting-${setting.key}`;
    const busy = saving ? t('platform::admin_settings.saving_reason') : undefined;

    const flip = (next: boolean) => {
        if (saving) {
            return;
        }

        router.post(
            `/admin/settings/${setting.key}`,
            sent(setting, store, next ? 'true' : 'false'),
            {
                preserveScroll: true,
                onStart: () => {
                    setSaving(true);
                    setError(undefined);
                },
                onError: (errors) => setError(errors.value),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Field orientation="horizontal" data-invalid={error ? true : undefined}>
            <FieldContent>
                <FieldLabel htmlFor={id} className="text-label-14 text-ink">
                    {setting.label}
                </FieldLabel>
                <FieldDescription id={`${id}-scope`} className="text-copy-13 text-ink-muted">
                    <Scope setting={setting} store={store} />
                </FieldDescription>
                {error ? <FieldError id={`${id}-error`}>{error}</FieldError> : null}
            </FieldContent>
            {/* On a span, never on the switch: both are Radix parts writing data-state (lesson 141).
                The reason is written for a screen reader too, since the span takes no focus. */}
            <Tooltip open={busy !== undefined && tip} onOpenChange={setTip}>
                <TooltipTrigger asChild>
                    <span className="inline-flex">
                        <Switch
                            id={id}
                            // The server's answer, never a guess (the switches show what is stored).
                            checked={on}
                            aria-disabled={busy === undefined ? undefined : true}
                            aria-describedby={[`${id}-scope`, error ? `${id}-error` : null, busy === undefined ? null : `${id}-busy`].filter(Boolean).join(' ')}
                            onCheckedChange={flip}
                            className="data-[state=unchecked]:bg-ink-subtle aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            data-test={`switch-${setting.key}`}
                        />
                    </span>
                </TooltipTrigger>
                {busy === undefined ? null : <TooltipContent>{busy}</TooltipContent>}
            </Tooltip>
            {busy === undefined ? null : (
                <span id={`${id}-busy`} className="sr-only">
                    {busy}
                </span>
            )}
        </Field>
    );
}

/** A number or a text: open to type in, saved by its own button (Geist's Fieldset). */
function Value({ setting, store }: { setting: SettingRowData; store: Store }) {
    const t = useTranslator();
    const isNumber = setting.type === 'INTEGER';
    // A sensitive setting is written, never read back, so its field starts empty whatever is stored.
    const stored = setting.sensitive ? '' : String(setting.value ?? '');
    const form = useForm({ value: stored });
    const changed = form.data.value !== stored;
    // The value as typed (frontend.md §1.7), with the bounds the module declared for it (its rules'
    // min and max, read by SettingDefinitionDto::bounds; checked by InMemorySettingsRegistry). A
    // number is whole, and required only where its smallest is above 0: an empty box is read as 0
    // (SettingsController::value). A text is checked for its length alone: whether it may be empty is
    // the module's to say (mayBeEmpty), and the page is not told.
    const rules: Rules = isNumber
        ? { required: setting.min !== null && setting.min > 0, number: { min: setting.min ?? undefined, max: setting.max ?? undefined } }
        : setting.type === 'TEXT'
          ? { length: { max: setting.max ?? undefined } }
          : {};
    const checks = useChecks([{ id: setting.key, label: setting.label, value: form.data.value, rules }]);
    const check = checks.box(setting.key, form.errors.value);
    const helper = setting.sensitive ? t('platform::admin_settings.unchanged') : undefined;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();

                if (!changed) {
                    return;
                }

                checks.submit(() => {
                    form.transform((data) => sent(setting, store, data.value) as typeof data);
                    form.post(`/admin/settings/${setting.key}`, {
                        preserveScroll: true,
                        // Saved: what was typed is now what is stored. A secret goes back to empty, in
                        // the field too - it is never shown again, not even to whoever typed it.
                        onSuccess: () => {
                            if (setting.sensitive) {
                                form.setData('value', '');
                            }

                            form.setDefaults({ value: setting.sensitive ? '' : form.data.value });
                        },
                    });
                });
            }}
            className="grid gap-3"
        >
            <FieldGroup className="gap-3">
                <Field orientation="responsive" data-invalid={check.message ? true : undefined}>
                    <FieldContent>
                        {/* The key has dots in it, so the field is found by attribute, not "#id". */}
                        <FieldLabel htmlFor={setting.key} className="text-label-14 text-ink">
                            {setting.label}
                        </FieldLabel>
                        <FieldDescription id={`${setting.key}-scope`} className="text-copy-13 text-ink-muted">
                            <Scope setting={setting} store={store} />
                        </FieldDescription>
                    </FieldContent>
                    {/* In a box of its own, so the field's row keeps its width rules and the input
                        its own: a number's box is narrow, a text's wider (Geist's Input sizes). */}
                    <div>
                        <Input
                            id={setting.key}
                            dir="ltr"
                            className={isNumber ? 'tw-figure w-full @md/field-group:w-40' : 'w-full @md/field-group:w-64'}
                            inputMode={isNumber ? 'numeric' : undefined}
                            // The bounds the module itself declared, so the field never offers a number
                            // the save would refuse.
                            min={setting.min ?? undefined}
                            max={setting.max ?? undefined}
                            // A shadcn Input wired to its check by hand (Fields.tsx's parts): its label and
                            // scope sit beside it, which the shared TextField does not lay out.
                            aria-required={check.required ? true : undefined}
                            aria-invalid={check.message ? true : undefined}
                            aria-describedby={describedBy(setting.key, helper, check.message, `${setting.key}-scope`)}
                            value={form.data.value}
                            onChange={(event) => {
                                form.setData('value', isNumber ? toLatinDigits(event.target.value) : event.target.value);
                                check.onType();
                            }}
                            onBlur={check.onLeave}
                        />
                    </div>
                </Field>
                {/* An instruction is helper text in Geist, never a placeholder. */}
                {helper === undefined ? null : (
                    <FieldDescription id={`${setting.key}-helper`} className="text-copy-13 text-ink-muted">
                        {helper}
                    </FieldDescription>
                )}
                <Messages id={setting.key} error={check.message} check={check} />
            </FieldGroup>

            <div className="flex justify-end gap-2">
                {changed ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-test={`cancel-${setting.key}`}
                        onClick={() => {
                            // Back to what is stored, so leaving a change changes nothing.
                            form.reset();
                            form.clearErrors();
                        }}
                    >
                        {t('platform::admin_settings.cancel')}
                    </Button>
                ) : null}
                <ActionButton
                    type="submit"
                    size="sm"
                    loading={form.processing}
                    disabledReason={changed ? checks.reason : t('platform::admin_settings.unchanged_reason')}
                    data-test={`save-${setting.key}`}
                >
                    {t('platform::admin_settings.save')}
                </ActionButton>
            </div>
        </form>
    );
}
