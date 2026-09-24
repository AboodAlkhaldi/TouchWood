import { Checkbox } from '@/components/ui/checkbox';
import { useTranslator } from '@/lib/t';

/*
| Choosing what a role allows (frontend.md §3.4 D3, and §3.3 C6 for one person).
|
| Actions are shown grouped by **business area** - "Staff and permissions", "Store settings and
| tax" - because that is how the people using this think about them, not in the order the modules
| happened to declare them. The area comes from the permission catalog itself (stage 2b, P2), so a
| module that ships later appears under its own heading without this component changing.
|
| An action the author does not hold is shown but cannot be ticked: nobody hands out what they do
| not have. It is shown rather than hidden so that an admin can see the shape of the whole system
| and understand why something is not theirs to give (access.md §1.5).
|
| Stores are not here at all. What a role allows and where a person may do it are two different
| questions, and the second is answered per staff member (§3.3 C6).
*/

export type PickerPermission = {
    name: string;
    label: string;
    group: string;
    storeFree: boolean;
    grantable: boolean;
};

export type PickerGroup = {
    key: string;
    label: string;
};

type Props = {
    permissions: PickerPermission[];
    groups: PickerGroup[];
    chosen: string[];
    onChange: (chosen: string[]) => void;
    /** Read-only: an admin looking at a role they may not change. */
    disabled?: boolean;
};

export function PermissionPicker({ permissions, groups, chosen, onChange, disabled = false }: Props) {
    const t = useTranslator();
    const held = new Set(chosen);

    function toggle(name: string, on: boolean) {
        onChange(on ? [...chosen, name] : chosen.filter((each) => each !== name));
    }

    return (
        <div className="grid gap-6">
            {groups.map((group) => {
                const inGroup = permissions.filter((permission) => permission.group === group.key);

                // A business area no module has declared anything for yet simply is not shown,
                // rather than appearing as an empty heading.
                if (inGroup.length === 0) {
                    return null;
                }

                const chosenHere = inGroup.filter((permission) => held.has(permission.name)).length;

                return (
                    <section key={group.key} className="rounded-lg border border-line bg-surface">
                        <header className="flex items-center justify-between border-b border-line px-4 py-3">
                            <h3 className="text-sm font-semibold text-ink">{group.label}</h3>
                            {chosenHere > 0 ? (
                                <span className="tw-figure rounded-pill bg-brand-soft px-2 py-0.5 text-xs text-brand">
                                    {t('access::roles.chosen_count', { count: chosenHere })}
                                </span>
                            ) : null}
                        </header>

                        <ul className="grid gap-0.5 p-2">
                            {inGroup.map((permission) => {
                                const locked = disabled || !permission.grantable;

                                return (
                                    <li key={permission.name}>
                                        <label
                                            className={[
                                                'flex items-start gap-3 rounded-md px-2 py-2 text-sm',
                                                locked ? 'text-ink-subtle' : 'text-ink hover:bg-surface-sunken',
                                            ].join(' ')}
                                            title={permission.grantable ? undefined : t('access::roles.not_yours')}
                                        >
                                            <Checkbox
                                                className="mt-0.5"
                                                checked={held.has(permission.name)}
                                                disabled={locked}
                                                onCheckedChange={(on) => toggle(permission.name, on === true)}
                                            />

                                            <span className="grid gap-0.5">
                                                <span>{permission.label}</span>
                                                {permission.storeFree ? (
                                                    <span className="text-xs text-ink-muted">
                                                        {t('access::roles.store_free')}
                                                    </span>
                                                ) : null}
                                            </span>
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}
