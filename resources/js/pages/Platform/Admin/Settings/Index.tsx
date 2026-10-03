import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button, EmptyState, FieldMessage, Fieldset, Input, Label, Toggle } from '@/components/geist';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SettingRowData, SettingsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E4 - the settings (frontend.md §3.5), in Geist's parts (1.10).
|
| Every declared setting this person may change, grouped by the module that declared it. **A row
| they may not change is not here at all**: each setting carries its own permission, so that is one
| answer per row rather than one for the page - which is why Access's numbers sit in a single Access
| section although a store's own settings and the staff security ones carry different permissions
| (owner, 2026-09-22).
|
| A store setting applies to the store in the header, and the section says which store that is. A
| global one is the same value everywhere, and only somebody who reaches every store may change it.
|
| A sensitive setting never shows its value (platform.md §1.3) - not even to the person changing it.
| The field is empty, and saying nothing leaves it as it was.
|
| Each row saves on its own. One button for the page would make a person who meant to change one
| number answer for every other number on the screen. So a module's section is Geist's Fieldset
| without a footer - its rows each carry their own save - and a yes-or-no setting is Geist's Toggle,
| which is what Geist uses for a single boolean setting.
*/

type Props = SettingsPage;

export default function Index({ groups, storeName }: Props) {
    const t = useTranslator();

    return (
        <AdminLayout
            title={t('platform::admin_settings.title')}
            subtitle={t('platform::admin_settings.subtitle')}
        >
            <div className="grid gap-6">
                <FormError />

                {groups.length === 0 ? (
                    <EmptyState
                        title={t('platform::admin_settings.none_title')}
                        description={t('platform::admin_settings.none')}
                    />
                ) : (
                    groups.map((group) => (
                        <Fieldset
                            key={group.module}
                            title={group.label}
                            // What the module's settings add up to in this store (platform.md §1.3).
                            subtitle={group.line ?? undefined}
                        >
                            {/* Edge to edge inside the section, so the lines between rows meet its sides. */}
                            <ul className="-mx-5 -mb-5 divide-y divide-line border-t border-line sm:-mx-6 sm:-mb-6">
                                {group.settings.map((setting) => (
                                    <li key={setting.key} className="px-5 py-4 sm:px-6">
                                        <Row setting={setting} storeName={storeName} />
                                    </li>
                                ))}
                            </ul>
                        </Fieldset>
                    ))
                )}
            </div>
        </AdminLayout>
    );
}

function Row({ setting, storeName }: { setting: SettingRowData; storeName: string | null }) {
    const t = useTranslator();

    /*
    | A setting is read until somebody says otherwise (owner, 2026-09-24).
    |
    | These are the numbers a shop runs on - how long a code lasts, how many wrong passwords lock
    | an account - and a screen of thirty open boxes invites a stray keystroke into one of them.
    | Pressing Edit opens the one box; saving closes it again.
    */
    const [editing, setEditing] = useState(false);

    const form = useForm({
        // A sensitive setting is written, never read back, so its field starts empty whatever is
        // stored. A boolean travels as the string a checkbox posts.
        value: setting.sensitive ? '' : String(setting.value ?? ''),
    });

    const isBoolean = setting.type === 'BOOLEAN';
    const isNumber = setting.type === 'INTEGER';

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/admin/settings/${setting.key}`, {
                    preserveScroll: true,
                    onSuccess: () => setEditing(false),
                });
            }}
            className="flex flex-wrap items-start justify-between gap-4"
        >
            <div className="grid min-w-0 gap-1">
                <Label htmlFor={setting.key}>{setting.label}</Label>

                <p className="text-copy-13 text-ink-muted">
                    {setting.scope === 'STORE' && storeName !== null
                        ? t('platform::admin_settings.in_store', { store: storeName })
                        : t('platform::admin_settings.everywhere')}

                    {setting.sensitive ? ` · ${t('platform::admin_settings.sensitive')}` : ''}

                    {/* A default that is empty means "not set yet": there is no value in force to name (owner, 2026-09-29). */}
                    {!setting.sensitive && setting.isDefault && String(setting.default ?? '') !== ''
                        ? ` · ${t('platform::admin_settings.is_default', { value: String(setting.default ?? '') })}`
                        : ''}
                </p>

                {/* A text field says its own error under itself; a toggle has no room, so here. */}
                {isBoolean ? <FieldMessage id={setting.key} error={form.errors.value} /> : null}
            </div>

            <div className="flex items-start gap-2">
                {isBoolean ? (
                    <div className="flex h-8 items-center">
                        <Toggle
                            id={setting.key}
                            checked={form.data.value === 'true' || form.data.value === '1'}
                            onChange={(on) => form.setData('value', on ? 'true' : 'false')}
                            // Read until Edit is pressed, and the tooltip says so.
                            disabledReason={editing ? undefined : t('platform::admin_settings.read_only_reason')}
                        />
                    </div>
                ) : (
                    <Input
                        id={setting.key}
                        size="small"
                        dir="ltr"
                        // Read-only rather than disabled: still reachable by Tab, its value still
                        // selectable, as before the move to Geist.
                        readOnly={!editing}
                        className={isNumber ? 'w-40' : 'w-64'}
                        inputMode={isNumber ? 'numeric' : undefined}
                        // The bounds the module itself declared, so the field never offers a
                        // number the save would refuse.
                        min={setting.min ?? undefined}
                        max={setting.max ?? undefined}
                        // An instruction is helper text in Geist, never a placeholder, and it only
                        // means something while the box is open.
                        helper={setting.sensitive && editing ? t('platform::admin_settings.unchanged') : undefined}
                        error={form.errors.value}
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData('value', isNumber ? toLatinDigits(event.target.value) : event.target.value)
                        }
                    />
                )}

                {editing ? (
                    <>
                        <Button
                            typeName="submit"
                            type="secondary"
                            size="small"
                            data-test={`save-${setting.key}`}
                            loading={form.processing}
                        >
                            {t('platform::admin_settings.save')}
                        </Button>

                        <Button
                            type="tertiary"
                            size="small"
                            onClick={() => {
                                // Back to what is stored, so leaving an edit changes nothing.
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            {t('platform::admin_settings.cancel')}
                        </Button>
                    </>
                ) : (
                    <Button
                        type="secondary"
                        size="small"
                        data-test={`edit-${setting.key}`}
                        onClick={() => setEditing(true)}
                    >
                        {t('platform::admin_settings.edit')}
                    </Button>
                )}
            </div>
        </form>
    );
}
