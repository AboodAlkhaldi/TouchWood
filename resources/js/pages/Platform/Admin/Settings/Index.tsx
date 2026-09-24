import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/AdminLayout';
import { FormError } from '@/components/FormError';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { toLatinDigits } from '@/lib/digits';
import { useTranslator } from '@/lib/t';
import type { SettingRowData, SettingsPage } from '@/types/generated/Modules/Platform/Presentation/Http/Resource';

/*
| E4 - the settings (frontend.md §3.5).
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
| number answer for every other number on the screen.
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
                    <p className="rounded-lg border border-line bg-surface p-6 text-sm text-ink-muted">
                        {t('platform::admin_settings.none')}
                    </p>
                ) : (
                    groups.map((group) => (
                        <section key={group.module} className="rounded-lg border border-line bg-surface">
                            <header className="border-b border-line px-4 py-3">
                                <h2 className="text-sm font-semibold text-ink">{group.label}</h2>
                            </header>

                            <ul className="divide-y divide-line">
                                {group.settings.map((setting) => (
                                    <li key={setting.key} className="p-4">
                                        <Row setting={setting} storeName={storeName} />
                                    </li>
                                ))}
                            </ul>
                        </section>
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
            className="flex flex-wrap items-end justify-between gap-4"
        >
            <div className="grid min-w-0 gap-1">
                <label htmlFor={setting.key} className="text-sm text-ink">
                    {setting.label}
                </label>

                <p className="text-xs text-ink-muted">
                    {setting.scope === 'STORE' && storeName !== null
                        ? t('platform::admin_settings.in_store', { store: storeName })
                        : t('platform::admin_settings.everywhere')}

                    {setting.sensitive ? ` · ${t('platform::admin_settings.sensitive')}` : ''}

                    {!setting.sensitive && setting.isDefault
                        ? ` · ${t('platform::admin_settings.is_default', { value: String(setting.default ?? '') })}`
                        : ''}
                </p>

                {form.errors.value ? (
                    <p className="text-xs text-bad" role="alert">
                        {form.errors.value}
                    </p>
                ) : null}
            </div>

            <div className="flex items-center gap-2">
                {isBoolean ? (
                    <Checkbox
                        id={setting.key}
                        disabled={! editing}
                        checked={form.data.value === 'true' || form.data.value === '1'}
                        onCheckedChange={(on) => form.setData('value', on === true ? 'true' : 'false')}
                    />
                ) : (
                    <Input
                        id={setting.key}
                        dir="ltr"
                        readOnly={! editing}
                        className={[
                            isNumber ? 'tw-figure w-40' : 'w-64',
                            // Plainly not typable, rather than looking typable and refusing.
                            editing ? '' : 'border-transparent bg-surface-sunken text-ink-muted',
                        ].join(' ')}
                        inputMode={isNumber ? 'numeric' : undefined}
                        // The bounds the module itself declared, so the field never offers a
                        // number the save would refuse.
                        min={setting.min ?? undefined}
                        max={setting.max ?? undefined}
                        placeholder={setting.sensitive ? t('platform::admin_settings.unchanged') : undefined}
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData('value', isNumber ? toLatinDigits(event.target.value) : event.target.value)
                        }
                    />
                )}

                {editing ? (
                    <>
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            data-test={`save-${setting.key}`}
                            disabled={form.processing}
                        >
                            {t('platform::admin_settings.save')}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
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
                        type="button"
                        variant="outline"
                        size="sm"
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
